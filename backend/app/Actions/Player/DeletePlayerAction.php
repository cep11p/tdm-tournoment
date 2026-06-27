<?php

namespace App\Actions\Player;

use App\Models\Player;
use Illuminate\Validation\ValidationException;

final class DeletePlayerAction
{
    public function __invoke(Player $player): void
    {
        if ($this->hasAssociatedHistory($player)) {
            throw ValidationException::withMessages([
                'player' => ['No se puede eliminar el jugador porque tiene historial asociado.'],
            ]);
        }

        $player->delete();
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
