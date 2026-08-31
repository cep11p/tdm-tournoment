<?php

namespace App\Actions\Game;

use App\Actions\TeamTie\PropagateTeamTieBracketCorrectionAction;
use App\Actions\TeamTie\RecalculateTeamTieOutcomeAction;
use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\CompetitionType;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Player;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Bracket\BracketPodiumSupport;
use App\Support\Competition\CompetitionEntryDisplayName;
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
        private readonly RecalculateTeamTieOutcomeAction $recalculateTeamTieOutcome,
        private readonly PropagateTeamTieBracketCorrectionAction $propagateTeamTieBracketCorrection,
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
                    'bracket',
                    'teamTieGame',
                    ...Game::DISPLAY_RELATIONS,
                ])
                ->lockForUpdate()
                ->findOrFail($game->id);

            TournamentLifecycleGuard::ensureMutableForGame($game);

            $this->correctionGuard->assertSourceCorrectable($game);

            $oldSnapshot = $this->snapshot($game);
            $setsCountBefore = $game->sets->count();
            $oldWinnerEntryId = (int) $game->winner_entry_id;
            $oldLoserEntryId = self::loserEntryIdForWinner($game, $oldWinnerEntryId);

            $competition = $game->competition;
            $setsToWin = (int) ($game->sets_to_win ?? $competition->sets_to_win);
            $pointsPerSet = (int) $competition->points_per_set;
            $newSets = $payload['sets'];

            $validatedResult = $this->validateFullResult($game, $newSets, $setsToWin, $pointsPerSet);
            $newWinnerEntryId = $validatedResult['winner_entry_id'];
            $newLoserEntryId = self::loserEntryIdForWinner($game, $newWinnerEntryId);

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
                    'oldParticipantId' => $oldWinnerEntryId,
                    'newParticipantId' => $newWinnerEntryId,
                    'context' => $this->winnerPropagationContext($game, $winnerDependency),
                    'kind' => 'winner',
                ];
            }

            if ($loserDependency !== null) {
                $destination = $lockedDestinations[(int) $loserDependency['game']->id];

                $propagations[] = [
                    'destination' => $destination,
                    'slot' => $loserDependency['slot'],
                    'oldParticipantId' => $oldLoserEntryId,
                    'newParticipantId' => $newLoserEntryId,
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

            $game->winner_entry_id = $newWinnerEntryId;
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
                ...Game::DISPLAY_RELATIONS,
            ]);

            $newSnapshot = $this->snapshot($game);

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GAME_RESULT_CORRECTED,
                logName: 'games',
                subject: $game,
                context: AuditContextBuilder::fromGame($game),
                old: $oldSnapshot,
                new: $newSnapshot,
                summary: array_merge([
                    'winner_changed' => $oldWinnerEntryId !== $newWinnerEntryId,
                    'old_winner_entry_id' => $oldWinnerEntryId,
                    'new_winner_entry_id' => $newWinnerEntryId,
                    'old_winner_display_name' => $oldSnapshot['winner_display_name'] ?? null,
                    'new_winner_display_name' => $newSnapshot['winner_display_name'] ?? null,
                    'sets_count_before' => $setsCountBefore,
                    'sets_count_after' => count($newSets),
                    'propagation' => $this->buildPropagationSummary(
                        winnerDependency: $winnerDependency,
                        loserDependency: $loserDependency,
                        lockedDestinations: $lockedDestinations,
                        oldWinnerId: $oldWinnerEntryId,
                        newWinnerId: $newWinnerEntryId,
                        oldLoserId: $oldLoserEntryId,
                        newLoserId: $newLoserEntryId,
                    ),
                ], $this->legacyWinnerChangeAuditFields($game, $oldSnapshot, $newSnapshot)),
                reason: $payload['reason'],
            ));

            if ($game->teamTieGame !== null) {
                $teamTieId = (int) $game->teamTieGame->team_tie_id;
                $teamTieBefore = \App\Models\TeamTie::query()
                    ->lockForUpdate()
                    ->findOrFail($teamTieId);
                $previousTeamTieWinnerEntryId = $teamTieBefore->winner_entry_id !== null
                    ? (int) $teamTieBefore->winner_entry_id
                    : null;

                $teamTieAfter = ($this->recalculateTeamTieOutcome)($teamTieId);
                $newTeamTieWinnerEntryId = $teamTieAfter->winner_entry_id !== null
                    ? (int) $teamTieAfter->winner_entry_id
                    : null;

                if (
                    $teamTieAfter->bracket_id !== null
                    && $previousTeamTieWinnerEntryId !== null
                    && $newTeamTieWinnerEntryId !== null
                    && $previousTeamTieWinnerEntryId !== $newTeamTieWinnerEntryId
                ) {
                    ($this->propagateTeamTieBracketCorrection)(
                        source: $teamTieAfter->fresh(),
                        previousWinnerEntryId: $previousTeamTieWinnerEntryId,
                        newWinnerEntryId: $newTeamTieWinnerEntryId,
                    );
                }
            }

            return $game->fresh([
                'competition',
                ...Game::DISPLAY_RELATIONS,
            ]);
        });
    }

    /**
     * @param  array{
     *     game: Game,
     *     slot: 'entry1_id'|'entry2_id',
     *     destination_round: int,
     *     destination_match: int,
     *     expected_entry_id: int,
     * }|null  $winnerDependency
     * @param  array{
     *     game: Game,
     *     slot: 'entry1_id'|'entry2_id',
     *     expected_entry_id: int,
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
     *     slot: 'entry1_id'|'entry2_id',
     *     destination_round?: int,
     *     destination_match?: int,
     *     expected_entry_id: int,
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
     *     slot: 'entry1_id'|'entry2_id',
     *     destination_round: int,
     *     destination_match: int,
     *     expected_entry_id: int,
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

    private static function loserEntryIdForWinner(Game $game, int $winnerEntryId): int
    {
        return (int) $game->entry1_id === $winnerEntryId
            ? (int) $game->entry2_id
            : (int) $game->entry1_id;
    }

    /**
     * @param  array<int, array{player1_score: int, player2_score: int}>  $setsPayload
     * @return array{
     *     winner_entry_id: int,
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
                'winner_entry_id' => (int) $game->entry1_id,
                'sets_won' => ['player1' => $player1Wins, 'player2' => $player2Wins],
            ];
        }

        return [
            'winner_entry_id' => (int) $game->entry2_id,
            'sets_won' => ['player1' => $player1Wins, 'player2' => $player2Wins],
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     winner_entry_id: int|null,
     *     winner_display_name: string|null,
     *     winner_id?: int|null,
     *     winner_name?: string|null,
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
        $game->loadMissing(['winnerEntry', 'competition']);

        $snapshot = [
            'status' => $game->status instanceof GameStatus
                ? $game->status->value
                : (string) $game->status,
            'winner_entry_id' => $game->winner_entry_id !== null ? (int) $game->winner_entry_id : null,
            'winner_display_name' => $game->winnerEntry !== null
                ? CompetitionEntryDisplayName::for($game->winnerEntry)
                : null,
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

        if ($this->isSinglesGame($game)) {
            $snapshot['winner_id'] = $game->singlesWinnerId();
            $snapshot['winner_name'] = self::playerDisplayName($game->singlesWinner());
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $oldSnapshot
     * @param  array<string, mixed>  $newSnapshot
     * @return array<string, mixed>
     */
    private function legacyWinnerChangeAuditFields(Game $game, array $oldSnapshot, array $newSnapshot): array
    {
        if (! $this->isSinglesGame($game)) {
            return [];
        }

        return [
            'old_winner_id' => $oldSnapshot['winner_id'] ?? null,
            'new_winner_id' => $newSnapshot['winner_id'] ?? null,
        ];
    }

    private function isSinglesGame(Game $game): bool
    {
        $type = $game->competition?->type;

        return $type instanceof CompetitionType
            ? $type === CompetitionType::Singles
            : (string) $type === CompetitionType::Singles->value || $type === null;
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
