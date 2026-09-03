<?php

namespace App\Support\Ranking;

use App\Enums\CompetitionFinalStandingSource;
use App\Models\RankingRule;
use Illuminate\Validation\ValidationException;

final class RankingRuleGuard
{
    public const STRICT_POSITION_SOURCES = [
        CompetitionFinalStandingSource::Final,
        CompetitionFinalStandingSource::ThirdPlacePlayoff,
    ];

    public static function assertValid(RankingRule $rule): void
    {
        if ((int) $rule->points < 0) {
            throw ValidationException::withMessages([
                'points' => ['Los puntos de la regla no pueden ser negativos.'],
            ]);
        }

        if ((int) $rule->priority < 0) {
            throw ValidationException::withMessages([
                'priority' => ['La prioridad de la regla no puede ser negativa.'],
            ]);
        }

        $source = $rule->source instanceof CompetitionFinalStandingSource
            ? $rule->source
            : CompetitionFinalStandingSource::tryFrom((string) $rule->source);

        if ($source === null) {
            throw ValidationException::withMessages([
                'source' => ['El origen de la regla de ranking no es válido.'],
            ]);
        }

        if ($rule->position === null) {
            return;
        }

        if (! in_array($source, self::STRICT_POSITION_SOURCES, true)) {
            throw ValidationException::withMessages([
                'position' => ['position solo se permite para final y third_place_playoff.'],
            ]);
        }
    }
}
