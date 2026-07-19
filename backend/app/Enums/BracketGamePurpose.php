<?php

namespace App\Enums;

enum BracketGamePurpose: string
{
    case Main = 'main';
    case ThirdPlace = 'third_place';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Llave principal',
            self::ThirdPlace => 'Tercer puesto',
        };
    }
}
