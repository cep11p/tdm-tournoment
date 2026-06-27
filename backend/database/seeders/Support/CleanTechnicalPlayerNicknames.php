<?php

namespace Database\Seeders\Support;

use App\Models\Player;

final class CleanTechnicalPlayerNicknames
{
    public static function cleanAmistosoPrefix(): int
    {
        return Player::query()
            ->where('nickname', 'like', 'amistoso-%')
            ->update(['nickname' => null]);
    }
}
