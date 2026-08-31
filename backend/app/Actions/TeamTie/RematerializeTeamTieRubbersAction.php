<?php

namespace App\Actions\TeamTie;

use App\Models\Game;
use App\Models\TeamTie;

final class RematerializeTeamTieRubbersAction
{
    public function __construct(
        private readonly MaterializeTeamTieGamesAction $materializeTeamTieGames,
    ) {}

    public function __invoke(TeamTie $teamTie): TeamTie
    {
        $teamTie->loadMissing(['teamTieGames', 'competition.teamTieFormat']);

        foreach ($teamTie->teamTieGames as $teamTieGame) {
            $gameId = (int) $teamTieGame->game_id;
            $teamTieGame->members()->delete();
            $teamTieGame->delete();
            Game::query()->whereKey($gameId)->delete();
        }

        if (! $teamTie->is_bye && $teamTie->entry1_id !== null && $teamTie->entry2_id !== null) {
            ($this->materializeTeamTieGames)($teamTie->fresh());
        }

        return $teamTie->fresh([
            'entry1',
            'entry2',
            'teamTieGames.game',
        ]);
    }
}
