<?php

namespace App\Actions\Game;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\CompetitionType;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Player;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionEntryDisplayName;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
                    ...Game::DISPLAY_RELATIONS,
                ])
                ->lockForUpdate()
                ->findOrFail($game->id);

            TournamentLifecycleGuard::ensureMutableForGame($game);

            if ($game->bracket_id !== null) {
                throw ValidationException::withMessages([
                    'game' => ['No se puede eliminar un partido de la llave eliminatoria.'],
                ]);
            }

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
                    'entry1_display_name' => $context['entry1_display_name'] ?? null,
                    'entry2_display_name' => $context['entry2_display_name'] ?? null,
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
     * @return array<string, mixed>
     */
    private function snapshot(Game $game): array
    {
        $sets = $game->relationLoaded('sets')
            ? $game->sets->sortBy('set_number')->values()
            : $game->sets()->orderBy('set_number')->get();

        $game->loadMissing(['entry1', 'entry2', 'winnerEntry', 'competition']);

        $snapshot = [
            'status' => $game->status instanceof GameStatus
                ? $game->status->value
                : (string) $game->status,
            'round' => $game->round,
            'entry1_id' => $game->entry1_id,
            'entry1_display_name' => $game->entry1 !== null
                ? CompetitionEntryDisplayName::for($game->entry1)
                : null,
            'entry2_id' => $game->entry2_id,
            'entry2_display_name' => $game->entry2 !== null
                ? CompetitionEntryDisplayName::for($game->entry2)
                : null,
            'winner_entry_id' => $game->winner_entry_id,
            'winner_display_name' => $game->winnerEntry !== null
                ? CompetitionEntryDisplayName::for($game->winnerEntry)
                : null,
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

        if ($game->competition?->type === CompetitionType::Singles) {
            $snapshot['player1_id'] = $game->singlesPlayer1Id();
            $snapshot['player1_name'] = self::playerDisplayName($game->singlesPlayer1());
            $snapshot['player2_id'] = $game->singlesPlayer2Id();
            $snapshot['player2_name'] = self::playerDisplayName($game->singlesPlayer2());
            $snapshot['winner_id'] = $game->singlesWinnerId();
            $snapshot['winner_name'] = self::playerDisplayName($game->singlesWinner());
        }

        return $snapshot;
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
