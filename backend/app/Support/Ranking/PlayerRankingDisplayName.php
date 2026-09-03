<?php

namespace App\Support\Ranking;

use App\Models\Player;

final class PlayerRankingDisplayName
{
    public static function for(Player $player): string
    {
        $name = trim(sprintf('%s %s', $player->first_name, $player->last_name));

        return $name !== '' ? $name : (string) $player->id;
    }
}
