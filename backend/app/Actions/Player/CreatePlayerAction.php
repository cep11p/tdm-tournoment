<?php

namespace App\Actions\Player;

use App\Models\Player;

final class CreatePlayerAction
{
    public function __invoke(array $payload): Player
    {
        $player = Player::query()->create([
            ...$payload,
            'active' => $payload['active'] ?? true,
        ]);

        return $player->refresh();
    }
}
