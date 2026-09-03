<?php

namespace App\Support\Ranking;

use App\Enums\CompetitionType;
use Illuminate\Validation\ValidationException;

final class RankingTypeGuard
{
    public const TEAM_UNSUPPORTED_MESSAGE = 'Stage E V1 no soporta ranking por equipos.';

    public static function assertSupported(CompetitionType|string|null $type): CompetitionType
    {
        $resolved = $type instanceof CompetitionType
            ? $type
            : CompetitionType::tryFrom((string) $type);

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'competition_type' => ['El tipo de competencia del ranking no es válido.'],
            ]);
        }

        if ($resolved->isTeam()) {
            throw ValidationException::withMessages([
                'competition_type' => [self::TEAM_UNSUPPORTED_MESSAGE],
            ]);
        }

        return $resolved;
    }
}
