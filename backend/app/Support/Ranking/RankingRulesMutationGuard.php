<?php

namespace App\Support\Ranking;

use App\Models\Ranking;
use Illuminate\Validation\ValidationException;

final class RankingRulesMutationGuard
{
    public const LOCKED_MESSAGE = 'Este ranking ya tiene puntos otorgados. Las reglas no se pueden modificar. Creá un ranking nuevo para un reglamento distinto.';

    public static function assertMutable(Ranking $ranking): void
    {
        if ($ranking->transactions()->exists()) {
            throw ValidationException::withMessages([
                'ranking' => [self::LOCKED_MESSAGE],
            ]);
        }
    }
}
