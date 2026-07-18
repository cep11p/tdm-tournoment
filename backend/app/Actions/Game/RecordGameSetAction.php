<?php

namespace App\Actions\Game;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Game\GameSetScoreValidator;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordGameSetAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly GameSetScoreValidator $scoreValidator,
    ) {}

    public function __invoke(Game $game, array $payload): Game
    {
        return DB::transaction(function () use ($game, $payload): Game {
            $game = Game::query()
                ->with(['competition.tournament', 'sets'])
                ->lockForUpdate()
                ->findOrFail($game->id);

            TournamentLifecycleGuard::ensureMutableForGame($game);

            if ($game->is_bye) {
                throw ValidationException::withMessages([
                    'game' => ['No se pueden registrar sets en un partido con BYE.'],
                ]);
            }

            if ($game->status === GameStatus::Finished) {
                throw ValidationException::withMessages([
                    'game' => ['El partido ya finalizó.'],
                ]);
            }

            $oldSetsWon = $game->setsWonCount($game->sets);
            $oldStatus = $game->status;

            $competition = $game->competition;
            // Fallback legacy: partidos sin snapshot (pre-PR3 o migración incompleta).
            $setsToWin = (int) ($game->sets_to_win ?? $competition->sets_to_win);
            $setNumber = (int) $payload['set_number'];

            if ($game->best_of !== null && $setNumber > $game->best_of) {
                throw ValidationException::withMessages([
                    'set_number' => [
                        sprintf('El partido es a mejor de %d y no admite más sets.', $game->best_of),
                    ],
                ]);
            }

            $setsWon = $game->setsWonCount($game->sets);

            if ($setsWon['player1'] >= $setsToWin
                || $setsWon['player2'] >= $setsToWin) {
                throw ValidationException::withMessages([
                    'game' => ['El partido ya tiene un ganador definido.'],
                ]);
            }

            $player1Score = (int) $payload['player1_score'];
            $player2Score = (int) $payload['player2_score'];

            $this->scoreValidator->validate(
                player1Score: $player1Score,
                player2Score: $player2Score,
                pointsPerSet: (int) $competition->points_per_set,
            );

            try {
                $game->sets()->create([
                    'set_number' => $setNumber,
                    'player1_score' => $player1Score,
                    'player2_score' => $player2Score,
                ]);
            } catch (QueryException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    throw ValidationException::withMessages([
                        'set_number' => ['Ya existe un set con ese número en el partido.'],
                    ]);
                }

                throw $exception;
            }

            $game->load('sets');
            $setsWon = $game->setsWonCount($game->sets);

            if ($setsWon['player1'] >= $setsToWin) {
                $game->winner_id = $game->player1_id;
                $game->status = GameStatus::Finished;
                $game->finished_at = now();
            } elseif ($setsWon['player2'] >= $setsToWin) {
                $game->winner_id = $game->player2_id;
                $game->status = GameStatus::Finished;
                $game->finished_at = now();
            } else {
                $game->winner_id = null;
                $game->status = GameStatus::InProgress;
                $game->finished_at = null;
            }

            $game->save();

            $newSetsWon = $game->setsWonCount($game->sets);
            $matchFinished = $game->status === GameStatus::Finished;

            $game->load([
                'competition',
                'player1:id,first_name,last_name,nickname',
                'player2:id,first_name,last_name,nickname',
                'winner:id,first_name,last_name,nickname',
                'sets',
            ]);

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GAME_SET_RECORDED,
                logName: 'games',
                subject: $game,
                context: AuditContextBuilder::fromGame($game),
                old: [
                    'player1_sets_won' => $oldSetsWon['player1'],
                    'player2_sets_won' => $oldSetsWon['player2'],
                    'status' => $oldStatus->value,
                ],
                new: [
                    'player1_sets_won' => $newSetsWon['player1'],
                    'player2_sets_won' => $newSetsWon['player2'],
                    'status' => $game->status->value,
                ],
                summary: [
                    'set_number' => $setNumber,
                    'player1_score' => $player1Score,
                    'player2_score' => $player2Score,
                    'match_finished' => $matchFinished,
                    'winner_id' => $game->winner_id,
                ],
            ));

            return $game;
        });
    }
}
