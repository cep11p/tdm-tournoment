<?php

namespace App\Actions\Game;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Player;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Bracket\BracketPodiumSupport;
use App\Support\Game\GameDependencyResolver;
use App\Support\Game\GameResultCorrectionGuard;
use App\Support\Game\GameSetScoreValidator;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CorrectFinishedGameResultAction
{
    public function __construct(
        private readonly GameResultCorrectionGuard $correctionGuard,
        private readonly GameDependencyResolver $dependencyResolver,
        private readonly GameSetScoreValidator $scoreValidator,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{reason: string, sets: array<int, array{player1_score: int, player2_score: int}>}  $payload
     */
    public function __invoke(Game $game, array $payload): Game
    {
        return DB::transaction(function () use ($game, $payload): Game {
            $game = Game::query()
                ->with([
                    'competition.tournament',
                    'sets',
                    'player1:id,first_name,last_name,nickname',
                    'player2:id,first_name,last_name,nickname',
                    'winner:id,first_name,last_name,nickname',
                    'bracket',
                ])
                ->lockForUpdate()
                ->findOrFail($game->id);

            TournamentLifecycleGuard::ensureMutableForGame($game);

            $this->correctionGuard->assertSourceCorrectable($game);

            $oldSnapshot = $this->snapshot($game);
            $setsCountBefore = $game->sets->count();
            $oldWinnerId = (int) $game->winner_id;
            $oldLoserId = self::loserIdForWinner($game, $oldWinnerId);

            $competition = $game->competition;
            $setsToWin = (int) ($game->sets_to_win ?? $competition->sets_to_win);
            $pointsPerSet = (int) $competition->points_per_set;
            $newSets = $payload['sets'];

            $validatedResult = $this->validateFullResult($game, $newSets, $setsToWin, $pointsPerSet);
            $newWinnerId = $validatedResult['winner_id'];
            $newLoserId = self::loserIdForWinner($game, $newWinnerId);

            $this->correctionGuard->assertNoRoundBeyondImmediate($game);

            $winnerDependency = $this->dependencyResolver->resolveWinnerDependency($game);
            $loserDependency = $this->dependencyResolver->resolveLoserThirdPlaceDependency($game);

            $destinationIds = [];

            if ($winnerDependency !== null) {
                $destinationIds[] = (int) $winnerDependency['game']->id;
            }

            if ($loserDependency !== null) {
                $destinationIds[] = (int) $loserDependency['game']->id;
            }

            $destinationIds = array_values(array_unique($destinationIds));
            sort($destinationIds);

            /** @var array<int, Game> $lockedDestinations */
            $lockedDestinations = [];

            foreach ($destinationIds as $destinationId) {
                $lockedDestinations[$destinationId] = Game::query()
                    ->lockForUpdate()
                    ->findOrFail($destinationId);
            }

            $propagations = [];

            if ($winnerDependency !== null) {
                $destination = $lockedDestinations[(int) $winnerDependency['game']->id];

                $propagations[] = [
                    'destination' => $destination,
                    'slot' => $winnerDependency['slot'],
                    'oldParticipantId' => $oldWinnerId,
                    'newParticipantId' => $newWinnerId,
                    'context' => $this->winnerPropagationContext($game, $winnerDependency),
                    'kind' => 'winner',
                ];
            }

            if ($loserDependency !== null) {
                $destination = $lockedDestinations[(int) $loserDependency['game']->id];

                $propagations[] = [
                    'destination' => $destination,
                    'slot' => $loserDependency['slot'],
                    'oldParticipantId' => $oldLoserId,
                    'newParticipantId' => $newLoserId,
                    'context' => 'third_place',
                    'kind' => 'loser',
                ];
            }

            $this->correctionGuard->assertPropagationsSafe($propagations);

            $game->sets()->delete();

            foreach ($newSets as $index => $setPayload) {
                $game->sets()->create([
                    'set_number' => $index + 1,
                    'player1_score' => (int) $setPayload['player1_score'],
                    'player2_score' => (int) $setPayload['player2_score'],
                ]);
            }

            $game->winner_id = $newWinnerId;
            $game->status = GameStatus::Finished;
            $game->finished_at = now();
            $game->save();

            foreach ($propagations as $propagation) {
                if ((int) $propagation['oldParticipantId'] === (int) $propagation['newParticipantId']) {
                    continue;
                }

                $destination = $propagation['destination'];
                $destination->{$propagation['slot']} = (int) $propagation['newParticipantId'];
                $destination->save();
            }

            $game->load([
                'competition',
                'player1:id,first_name,last_name,nickname',
                'player2:id,first_name,last_name,nickname',
                'winner:id,first_name,last_name,nickname',
                'sets',
            ]);

            $newSnapshot = $this->snapshot($game);

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GAME_RESULT_CORRECTED,
                logName: 'games',
                subject: $game,
                context: AuditContextBuilder::fromGame($game),
                old: $oldSnapshot,
                new: $newSnapshot,
                summary: [
                    'winner_changed' => $oldWinnerId !== $newWinnerId,
                    'old_winner_id' => $oldWinnerId,
                    'new_winner_id' => $newWinnerId,
                    'sets_count_before' => $setsCountBefore,
                    'sets_count_after' => count($newSets),
                    'propagation' => $this->buildPropagationSummary(
                        winnerDependency: $winnerDependency,
                        loserDependency: $loserDependency,
                        lockedDestinations: $lockedDestinations,
                        oldWinnerId: $oldWinnerId,
                        newWinnerId: $newWinnerId,
                        oldLoserId: $oldLoserId,
                        newLoserId: $newLoserId,
                    ),
                ],
                reason: $payload['reason'],
            ));

            return $game;
        });
    }

    /**
     * @param  array{
     *     game: Game,
     *     slot: 'player1_id'|'player2_id',
     *     destination_round: int,
     *     destination_match: int,
     *     expected_player_id: int,
     * }|null  $winnerDependency
     * @param  array{
     *     game: Game,
     *     slot: 'player1_id'|'player2_id',
     *     expected_player_id: int,
     * }|null  $loserDependency
     * @param  array<int, Game>  $lockedDestinations
     * @return array<string, mixed>
     */
    private function buildPropagationSummary(
        ?array $winnerDependency,
        ?array $loserDependency,
        array $lockedDestinations,
        int $oldWinnerId,
        int $newWinnerId,
        int $oldLoserId,
        int $newLoserId,
    ): array {
        return [
            'winner' => $this->buildSinglePropagationSummary(
                dependency: $winnerDependency,
                lockedDestinations: $lockedDestinations,
                oldParticipantId: $oldWinnerId,
                newParticipantId: $newWinnerId,
                unchangedReason: 'winner_unchanged',
            ),
            'loser' => $this->buildSinglePropagationSummary(
                dependency: $loserDependency,
                lockedDestinations: $lockedDestinations,
                oldParticipantId: $oldLoserId,
                newParticipantId: $newLoserId,
                unchangedReason: 'loser_unchanged',
            ),
        ];
    }

    /**
     * @param  array{
     *     game: Game,
     *     slot: 'player1_id'|'player2_id',
     *     destination_round?: int,
     *     destination_match?: int,
     *     expected_player_id: int,
     * }|null  $dependency
     * @param  array<int, Game>  $lockedDestinations
     * @return array<string, mixed>
     */
    private function buildSinglePropagationSummary(
        ?array $dependency,
        array $lockedDestinations,
        int $oldParticipantId,
        int $newParticipantId,
        string $unchangedReason,
    ): array {
        if ($dependency === null) {
            return [
                'applied' => false,
                'reason' => 'not_applicable',
            ];
        }

        if ($oldParticipantId === $newParticipantId) {
            return [
                'applied' => false,
                'reason' => $unchangedReason,
            ];
        }

        $destination = $lockedDestinations[(int) $dependency['game']->id] ?? $dependency['game'];

        $summary = [
            'applied' => true,
            'destination_game_id' => $destination->id,
            'slot' => $dependency['slot'],
            'before' => $oldParticipantId,
            'after' => $newParticipantId,
        ];

        if (isset($dependency['destination_round'])) {
            $summary['destination_round'] = $destination->round;
            $summary['destination_bracket_round'] = $dependency['destination_round'];
            $summary['destination_bracket_match'] = $dependency['destination_match'];
        }

        return $summary;
    }

    /**
     * @param  array{
     *     game: Game,
     *     slot: 'player1_id'|'player2_id',
     *     destination_round: int,
     *     destination_match: int,
     *     expected_player_id: int,
     * }  $winnerDependency
     * @return 'final'|'next_round'
     */
    private function winnerPropagationContext(Game $source, array $winnerDependency): string
    {
        $source->loadMissing('bracket');
        $bracket = $source->bracket;

        if ($bracket === null) {
            return 'next_round';
        }

        $finalRound = BracketPodiumSupport::finalRound($bracket);

        if ((int) $winnerDependency['destination_round'] === $finalRound) {
            return 'final';
        }

        return 'next_round';
    }

    private static function loserIdForWinner(Game $game, int $winnerId): int
    {
        return (int) $game->player1_id === $winnerId
            ? (int) $game->player2_id
            : (int) $game->player1_id;
    }

    /**
     * @param  array<int, array{player1_score: int, player2_score: int}>  $setsPayload
     * @return array{
     *     winner_id: int,
     *     sets_won: array{player1: int, player2: int},
     * }
     */
    private function validateFullResult(
        Game $game,
        array $setsPayload,
        int $setsToWin,
        int $pointsPerSet,
    ): array {
        if ($setsPayload === []) {
            throw ValidationException::withMessages([
                'sets' => ['Se requiere al menos un set.'],
            ]);
        }

        if ($game->best_of !== null && count($setsPayload) > $game->best_of) {
            throw ValidationException::withMessages([
                'sets' => [
                    sprintf('El partido es a mejor de %d y no admite más sets.', $game->best_of),
                ],
            ]);
        }

        $player1Wins = 0;
        $player2Wins = 0;
        $decisiveReached = false;

        foreach ($setsPayload as $index => $setPayload) {
            $player1Score = (int) $setPayload['player1_score'];
            $player2Score = (int) $setPayload['player2_score'];

            $this->scoreValidator->validate(
                player1Score: $player1Score,
                player2Score: $player2Score,
                pointsPerSet: $pointsPerSet,
                errorField: "sets.{$index}.player1_score",
            );

            if ($player1Score > $player2Score) {
                $player1Wins++;
            } else {
                $player2Wins++;
            }

            $matchDecided = $player1Wins >= $setsToWin || $player2Wins >= $setsToWin;

            if ($matchDecided) {
                if ($index !== count($setsPayload) - 1) {
                    throw ValidationException::withMessages([
                        'sets' => ['No se permiten sets posteriores al set decisivo.'],
                    ]);
                }

                $decisiveReached = true;
            }
        }

        if (! $decisiveReached) {
            throw ValidationException::withMessages([
                'sets' => ['Ningún jugador alcanzó la cantidad de sets necesarios para ganar el partido.'],
            ]);
        }

        if ($player1Wins >= $setsToWin && $player2Wins >= $setsToWin) {
            throw ValidationException::withMessages([
                'sets' => ['El resultado corregido no puede definir dos ganadores.'],
            ]);
        }

        if ($player1Wins >= $setsToWin) {
            return [
                'winner_id' => (int) $game->player1_id,
                'sets_won' => ['player1' => $player1Wins, 'player2' => $player2Wins],
            ];
        }

        return [
            'winner_id' => (int) $game->player2_id,
            'sets_won' => ['player1' => $player1Wins, 'player2' => $player2Wins],
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     winner_id: int|null,
     *     winner_name: string|null,
     *     finished_at: string|null,
     *     sets: array<int, array{set_number: int, player1_score: int, player2_score: int}>,
     *     sets_won: array{player1: int, player2: int}
     * }
     */
    private function snapshot(Game $game): array
    {
        $sets = $game->relationLoaded('sets')
            ? $game->sets->sortBy('set_number')->values()
            : $game->sets()->orderBy('set_number')->get();

        $setsWon = $game->setsWonCount($sets);

        return [
            'status' => $game->status instanceof GameStatus
                ? $game->status->value
                : (string) $game->status,
            'winner_id' => $game->winner_id,
            'winner_name' => self::playerDisplayName($game->winner),
            'finished_at' => $game->finished_at?->toIso8601String(),
            'sets' => $sets
                ->map(fn ($set): array => [
                    'set_number' => (int) $set->set_number,
                    'player1_score' => (int) $set->player1_score,
                    'player2_score' => (int) $set->player2_score,
                ])
                ->values()
                ->all(),
            'sets_won' => $setsWon,
        ];
    }

    private static function playerDisplayName(?Player $player): ?string
    {
        if ($player === null) {
            return null;
        }

        $name = trim(sprintf('%s %s', $player->first_name, $player->last_name));

        return $name !== '' ? $name : null;
    }
}
