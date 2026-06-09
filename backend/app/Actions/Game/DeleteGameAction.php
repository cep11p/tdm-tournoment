<?php

namespace App\Actions\Game;

use App\Models\Game;

final class DeleteGameAction
{
    public function __invoke(Game $game): void
    {
        $game->delete();
    }
}
