<?php

namespace App\Support\Competition;

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\TeamTieFormat;
use App\Support\TeamTie\TeamTieFormatValidator;
use Illuminate\Validation\ValidationException;

final class TeamCompetitionSchedulingGuard
{
    public const FORMAT_REQUIRED_MESSAGE = 'La competencia por equipos debe tener un formato de enfrentamiento configurado.';

    public static function ensureGamesRoundRobinAllowed(Competition $competition): void
    {
        if (self::isTeamCompetition($competition)) {
            throw ValidationException::withMessages([
                'group' => ['La generación de partidos individuales no aplica a competencias por equipos.'],
            ]);
        }
    }

    public static function ensureFormatConfigured(Competition $competition): void
    {
        if (! self::isTeamCompetition($competition)) {
            return;
        }

        if ($competition->team_tie_format_id === null) {
            throw ValidationException::withMessages([
                'competition' => [self::FORMAT_REQUIRED_MESSAGE],
            ]);
        }

        $format = TeamTieFormat::query()
            ->whereKey($competition->team_tie_format_id)
            ->where('active', true)
            ->with('slots')
            ->first();

        if ($format === null) {
            throw ValidationException::withMessages([
                'competition' => ['El formato de enfrentamiento configurado no está disponible.'],
            ]);
        }

        TeamTieFormatValidator::ensureValid($format);
    }

    public static function ensureRoundRobinAllowed(Competition $competition): void
    {
        if (! self::isTeamCompetition($competition)) {
            return;
        }

        self::ensureFormatConfigured($competition);
    }

    private static function isTeamCompetition(Competition $competition): bool
    {
        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        return $type === CompetitionType::Team;
    }
}
