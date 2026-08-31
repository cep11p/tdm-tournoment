<?php

namespace App\Actions\TeamTie;

use App\Models\Game;
use App\Models\TeamTie;

final class DeleteTeamTieWithRubbersAction
{
    public function __invoke(TeamTie $teamTie): void
    {
        $teamTie->load('teamTieGames');

        foreach ($teamTie->teamTieGames as $teamTieGame) {
            $gameId = (int) $teamTieGame->game_id;
            $teamTieGame->delete();
            Game::query()->whereKey($gameId)->delete();
        }

        $teamTie->delete();
    }
}
