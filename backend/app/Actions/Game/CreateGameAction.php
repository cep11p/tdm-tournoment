<?php

namespace App\Actions\Game;

use App\Enums\GameStatus;
use App\Models\Game;

final class CreateGameAction
{
    public function __invoke(array $payload): Game
    {
        $payload['status'] ??= GameStatus::Pending;
        $payload['winner_id'] ??= null;

        return Game::query()->create($payload);
    }
}
