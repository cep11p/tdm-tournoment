<?php

namespace App\Actions\Player;

use App\Models\Player;

final class UpdatePlayerAction
{
    public function __invoke(Player $player, array $payload): Player
    {
        $player->fill($payload);
        $player->save();

        return $player->refresh();
    }
}
