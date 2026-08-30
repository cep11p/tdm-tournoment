<?php

namespace App\Support\Competition;

use App\Enums\CompetitionType;
use App\Models\Competition;
use Illuminate\Validation\ValidationException;

final class TeamCompetitionSchedulingGuard
{
    public const ROUND_ROBIN_UNAVAILABLE_MESSAGE = 'La generación de enfrentamientos por equipos estará disponible en la siguiente etapa.';

    public static function ensureRoundRobinAllowed(Competition $competition): void
    {
        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        if ($type === CompetitionType::Team) {
            throw ValidationException::withMessages([
                'group' => [self::ROUND_ROBIN_UNAVAILABLE_MESSAGE],
            ]);
        }
    }
}
