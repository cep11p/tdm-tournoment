<?php

namespace App\Support\TeamTie;

use App\Enums\GameStatus;
use App\Enums\TeamTieStatus;
use App\Models\TeamTieGame;
use Illuminate\Validation\ValidationException;

final class TeamTieGameLineupGuard
{
    public static function assertEditable(TeamTieGame $teamTieGame): void
    {
        $teamTieGame->loadMissing(['teamTie.competition', 'game.sets']);

        $teamTie = $teamTieGame->teamTie;
        $game = $teamTieGame->game;

        if ($teamTie === null) {
            throw ValidationException::withMessages([
                'team_tie_game' => ['El partido interno no está asociado a un enfrentamiento válido.'],
            ]);
        }

        if (in_array($teamTie->status, [TeamTieStatus::Finished, TeamTieStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'team_tie' => ['No se puede modificar el lineup porque el enfrentamiento ya finalizó o fue cancelado.'],
            ]);
        }

        if ($game === null) {
            throw ValidationException::withMessages([
                'game' => ['El partido interno no tiene un partido asociado.'],
            ]);
        }

        if ($game->status !== GameStatus::Pending) {
            throw ValidationException::withMessages([
                'game' => ['No se puede modificar el lineup porque el partido ya comenzó, finalizó o ya no es necesario.'],
            ]);
        }

        if ($game->sets()->exists()) {
            throw ValidationException::withMessages([
                'game' => ['No se puede modificar el lineup porque el partido ya tiene sets registrados.'],
            ]);
        }
    }
}
