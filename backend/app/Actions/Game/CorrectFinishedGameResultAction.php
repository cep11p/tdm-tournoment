<?php

namespace App\Actions\Game;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Player;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Game\GameResultCorrectionGuard;
use App\Support\Game\GameSetScoreValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CorrectFinishedGameResultAction
{
    public function __construct(
        private readonly GameResultCorrectionGuard $correctionGuard,
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
                    'competition',
                    'sets',
                    'player1:id,first_name,last_name,nickname',
                    'player2:id,first_name,last_name,nickname',
                    'winner:id,first_name,last_name,nickname',
                ])
                ->lockForUpdate()
                ->findOrFail($game->id);

            $this->correctionGuard->assertCanCorrect($game);

            $oldSnapshot = $this->snapshot($game);
            $setsCountBefore = $game->sets->count();
            $oldWinnerId = $game->winner_id;

            $competition = $game->competition;
            $setsToWin = (int) ($game->sets_to_win ?? $competition->sets_to_win);
            $pointsPerSet = (int) $competition->points_per_set;
            $newSets = $payload['sets'];

            $this->validateFullResult($game, $newSets, $setsToWin, $pointsPerSet);

            $game->sets()->delete();

            foreach ($newSets as $index => $setPayload) {
                $game->sets()->create([
                    'set_number' => $index + 1,
                    'player1_score' => (int) $setPayload['player1_score'],
                    'player2_score' => (int) $setPayload['player2_score'],
                ]);
            }

            $game->load('sets');
            $setsWon = $game->setsWonCount($game->sets);

            if ($setsWon['player1'] >= $setsToWin) {
                $game->winner_id = $game->player1_id;
            } elseif ($setsWon['player2'] >= $setsToWin) {
                $game->winner_id = $game->player2_id;
            } else {
                throw ValidationException::withMessages([
                    'sets' => ['El resultado corregido no define un ganador válido.'],
                ]);
            }

            $game->status = GameStatus::Finished;
            $game->finished_at = now();
            $game->save();

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
                    'winner_changed' => (int) $oldWinnerId !== (int) $game->winner_id,
                    'old_winner_id' => $oldWinnerId,
                    'new_winner_id' => $game->winner_id,
                    'sets_count_before' => $setsCountBefore,
                    'sets_count_after' => count($newSets),
                    'dependent_games_detected' => [],
                ],
                reason: $payload['reason'],
            ));

            return $game;
        });
    }

    /**
     * @param  array<int, array{player1_score: int, player2_score: int}>  $setsPayload
     */
    private function validateFullResult(
        Game $game,
        array $setsPayload,
        int $setsToWin,
        int $pointsPerSet,
    ): void {
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
