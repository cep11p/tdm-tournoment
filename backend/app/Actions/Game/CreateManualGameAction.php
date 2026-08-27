<?php

namespace App\Actions\Game;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\ResolveSinglesEntryForPlayer;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;

final class CreateManualGameAction
{
    public function __construct(
        private readonly CreateGameAction $createGame,
        private readonly ResolveSinglesEntryForPlayer $resolveSinglesEntryForPlayer,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(array $payload): Game
    {
        $competition = Competition::query()->findOrFail($payload['competition_id']);
        TournamentLifecycleGuard::ensureMutableForCompetition($competition);

        $entry1 = ($this->resolveSinglesEntryForPlayer)($competition, (int) $payload['player1_id']);
        $entry2 = ($this->resolveSinglesEntryForPlayer)($competition, (int) $payload['player2_id']);

        return DB::transaction(function () use ($payload, $entry1, $entry2): Game {
            $game = ($this->createGame)([
                ...$payload,
                'entry1_id' => $entry1->id,
                'entry2_id' => $entry2->id,
            ]);

            $game->load(Game::DISPLAY_RELATIONS);
            $game->loadMissing('competition');

            $context = AuditContextBuilder::fromGame($game);
            $pointsPerSet = $game->competition?->points_per_set;

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GAME_CREATED,
                logName: 'games',
                subject: $game,
                context: $context,
                new: [
                    'player1_id' => $game->singlesPlayer1Id(),
                    'player2_id' => $game->singlesPlayer2Id(),
                    'round' => $game->round,
                    'group_id' => $game->group_id,
                    'bracket_id' => $game->bracket_id,
                    'best_of' => $game->best_of,
                    'sets_to_win' => $game->sets_to_win,
                    'points_per_set' => $pointsPerSet,
                    'status' => $game->status instanceof GameStatus ? $game->status->value : (string) $game->status,
                    'is_bye' => (bool) $game->is_bye,
                ],
                summary: [
                    'game_id' => $game->id,
                    'player1_name' => $context['player1_name'] ?? null,
                    'player2_name' => $context['player2_name'] ?? null,
                    'round' => $game->round,
                ],
            ));

            return $game;
        });
    }
}
