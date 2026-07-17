<?php

namespace App\Actions\Game;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

final class CreateManualGameAction
{
    private const GAME_RELATIONS = [
        'competition',
        'group',
        'bracket',
        'player1:id,first_name,last_name,nickname',
        'player2:id,first_name,last_name,nickname',
    ];

    public function __construct(
        private readonly CreateGameAction $createGame,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(array $payload): Game
    {
        return DB::transaction(function () use ($payload): Game {
            $game = ($this->createGame)($payload);
            $game->load(self::GAME_RELATIONS);
            $game->loadMissing('competition');

            $context = AuditContextBuilder::fromGame($game);
            $pointsPerSet = $game->competition?->points_per_set;

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GAME_CREATED,
                logName: 'games',
                subject: $game,
                context: $context,
                new: [
                    'player1_id' => $game->player1_id,
                    'player2_id' => $game->player2_id,
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
