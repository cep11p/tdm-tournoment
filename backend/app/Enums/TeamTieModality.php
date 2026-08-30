<?php

namespace App\Enums;

enum TeamTieModality: string
{
    case Singles = 'singles';
    case Doubles = 'doubles';

    public function label(): string
    {
        return match ($this) {
            self::Singles => 'Singles',
            self::Doubles => 'Dobles',
        };
    }
}
