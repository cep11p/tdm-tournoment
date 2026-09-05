<?php

namespace App\Support\Ranking;

use App\Enums\CompetitionFinalStandingSource;
use App\Models\RankingRule;
use Illuminate\Validation\ValidationException;

final class RankingRuleGuard
{
    /**
     * @var list<CompetitionFinalStandingSource>
     */
    public const FLAT_SOURCES = [
        CompetitionFinalStandingSource::Semifinal,
        CompetitionFinalStandingSource::Quarterfinal,
        CompetitionFinalStandingSource::RoundOf16,
        CompetitionFinalStandingSource::RoundOf32,
        CompetitionFinalStandingSource::PlayIn,
        CompetitionFinalStandingSource::GroupStage,
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

        $position = $rule->position === null || $rule->position === ''
            ? null
            : (int) $rule->position;

        if ($source === CompetitionFinalStandingSource::Final) {
            if (! in_array($position, [1, 2], true)) {
                throw ValidationException::withMessages([
                    'position' => ['La posición de la Final debe ser 1 (campeón) o 2 (subcampeón).'],
                ]);
            }

            return;
        }

        if ($source === CompetitionFinalStandingSource::ThirdPlacePlayoff) {
            if (! in_array($position, [3, 4], true)) {
                throw ValidationException::withMessages([
                    'position' => ['La posición del partido por el tercer puesto debe ser 3 o 4.'],
                ]);
            }

            return;
        }

        if (in_array($source, self::FLAT_SOURCES, true)) {
            if ($position !== null) {
                throw ValidationException::withMessages([
                    'position' => ['Este resultado no admite una posición específica.'],
                ]);
            }

            return;
        }

        throw ValidationException::withMessages([
            'source' => ['El origen de la regla de ranking no es válido.'],
        ]);
    }
}
