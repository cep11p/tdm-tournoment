<?php

namespace App\Actions\Game;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Player;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;

final class DeleteManualGameAction
{
    public function __construct(
        private readonly DeleteGameAction $deleteGame,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(Game $game): void
    {
        DB::transaction(function () use ($game): void {
            $game = Game::query()
                ->with([
                    'competition.tournament',
                    'group',
                    'bracket',
                    'player1:id,first_name,last_name,nickname',
                    'player2:id,first_name,last_name,nickname',
                    'winner:id,first_name,last_name,nickname',
                    'sets',
                ])
                ->lockForUpdate()
                ->findOrFail($game->id);

            TournamentLifecycleGuard::ensureMutableForGame($game);

            $context = AuditContextBuilder::fromGame($game);
            $snapshot = $this->snapshot($game);
            $setsRemoved = count($snapshot['sets']);

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GAME_DELETED,
                logName: 'games',
                subject: $game,
                context: $context,
                old: $snapshot,
                summary: [
                    'game_id' => $game->id,
                    'player1_name' => $context['player1_name'] ?? null,
                    'player2_name' => $context['player2_name'] ?? null,
                    'sets_removed' => $setsRemoved,
                ],
                reason: null,
            ));

            ($this->deleteGame)($game);
        });
    }

    /**
     * @return array{
     *     status: string,
     *     round: string|null,
     *     player1_id: int|null,
     *     player1_name: string|null,
     *     player2_id: int|null,
     *     player2_name: string|null,
     *     winner_id: int|null,
     *     winner_name: string|null,
     *     best_of: int|null,
     *     sets_to_win: int|null,
     *     points_per_set: int|null,
     *     is_bye: bool,
     *     sets: array<int, array{set_number: int, player1_score: int, player2_score: int}>
     * }
     */
    private function snapshot(Game $game): array
    {
        $sets = $game->relationLoaded('sets')
            ? $game->sets->sortBy('set_number')->values()
            : $game->sets()->orderBy('set_number')->get();

        return [
            'status' => $game->status instanceof GameStatus
                ? $game->status->value
                : (string) $game->status,
            'round' => $game->round,
            'player1_id' => $game->player1_id,
            'player1_name' => self::playerDisplayName($game->player1),
            'player2_id' => $game->player2_id,
            'player2_name' => self::playerDisplayName($game->player2),
            'winner_id' => $game->winner_id,
            'winner_name' => self::playerDisplayName($game->winner),
            'best_of' => $game->best_of,
            'sets_to_win' => $game->sets_to_win,
            'points_per_set' => $game->competition?->points_per_set,
            'is_bye' => (bool) $game->is_bye,
            'sets' => $sets
                ->map(fn ($set): array => [
                    'set_number' => (int) $set->set_number,
                    'player1_score' => (int) $set->player1_score,
                    'player2_score' => (int) $set->player2_score,
                ])
                ->values()
                ->all(),
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
