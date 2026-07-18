<?php

namespace App\Enums;

enum ThirdPlaceMode: string
{
    case Shared = 'shared';
    case Playoff = 'playoff';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Shared => 'Compartido',
            self::Playoff => 'Partido por tercer puesto',
            self::None => 'Sin tercer puesto',
        };
    }
}
