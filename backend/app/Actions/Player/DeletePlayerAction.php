<?php

namespace App\Actions\Player;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Player;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditPlayerAttributes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeletePlayerAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(Player $player): void
    {
        if ($this->hasAssociatedHistory($player)) {
            throw ValidationException::withMessages([
                'player' => ['No se puede eliminar el jugador porque tiene historial asociado.'],
            ]);
        }

        DB::transaction(function () use ($player): void {
            $player->load(['category:id,name', 'club:id,name']);

            $context = AuditContextBuilder::fromPlayer($player);
            $snapshot = AuditPlayerAttributes::snapshot($player);
            $playerId = $player->id;

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::PLAYER_DELETED,
                logName: 'players',
                subject: $player,
                context: $context,
                old: $snapshot,
                summary: [
                    'player_id' => $playerId,
                    'player_name' => $context['player_name'],
                ],
            ));

            $player->delete();
        });
    }

    private function hasAssociatedHistory(Player $player): bool
    {
        return $player->registrations()->exists()
            || $player->groupPlayers()->exists()
            || $player->gamesAsPlayer1()->exists()
            || $player->gamesAsPlayer2()->exists()
            || $player->wonGames()->exists()
            || $player->manualTiebreakPlayers()->exists();
    }
}
