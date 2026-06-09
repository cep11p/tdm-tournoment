<?php

namespace App\Actions\Player;

use App\Models\Player;

final class CreatePlayerAction
{
    public function __invoke(array $payload): Player
    {
        return Player::query()->create($payload);
    }
}
