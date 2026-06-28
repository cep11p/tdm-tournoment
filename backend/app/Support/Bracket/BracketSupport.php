<?php

namespace App\Support\Bracket;

use Illuminate\Validation\ValidationException;

final class BracketSupport
{
    public const MAX_BRACKET_SIZE = 64;

    public const PLAY_IN_ROUND_LABEL = 'Ronda clasificatoria';

    public static function nextPowerOfTwo(int $count): int
    {
        if ($count <= 1) {
            return 2;
        }

        $power = 1;

        while ($power < $count) {
            $power <<= 1;
        }

        return $power;
    }

    public static function roundLabelFor(int $playersInRound): string
    {
        return match ($playersInRound) {
            32 => '16avos de final',
            16 => '8vos de final',
            8 => 'Cuartos de final',
            4 => 'Semifinal',
            2 => 'Final',
            default => throw ValidationException::withMessages([
                'bracket' => [
                    sprintf('Cantidad de jugadores inválida para la ronda: %d.', $playersInRound),
                ],
            ]),
        };
    }
}
