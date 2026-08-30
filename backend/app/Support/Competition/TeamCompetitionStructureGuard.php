<?php

namespace App\Support\Competition;

use App\Enums\CompetitionType;
use App\Models\Competition;
use Illuminate\Validation\ValidationException;

final class TeamCompetitionStructureGuard
{
    public const FORMAT_LOCK_MESSAGE = 'No se puede modificar el formato de enfrentamiento porque ya hay enfrentamientos generados.';

    public const REGISTRATIONS_LOCK_MESSAGE = 'No se pueden modificar las inscripciones porque ya hay enfrentamientos generados.';

    public static function hasTeamTies(Competition $competition): bool
    {
        return $competition->teamTies()->exists();
    }

    public static function ensureFormatEditable(Competition $competition, string $field = 'team_tie_format_id'): void
    {
        if (! self::isTeamCompetition($competition)) {
            return;
        }

        if (self::hasTeamTies($competition)) {
            throw ValidationException::withMessages([
                $field => [self::FORMAT_LOCK_MESSAGE],
            ]);
        }
    }

    public static function ensureRegistrationsEditable(Competition $competition, string $field = 'competition'): void
    {
        if (! self::isTeamCompetition($competition)) {
            return;
        }

        if (self::hasTeamTies($competition)) {
            throw ValidationException::withMessages([
                $field => [self::REGISTRATIONS_LOCK_MESSAGE],
            ]);
        }
    }

    public static function ensureTeamSizeEditable(Competition $competition, string $field = 'team_size'): void
    {
        if (! self::isTeamCompetition($competition)) {
            return;
        }

        if (self::hasTeamTies($competition)) {
            throw ValidationException::withMessages([
                $field => [self::FORMAT_LOCK_MESSAGE],
            ]);
        }
    }

    private static function isTeamCompetition(Competition $competition): bool
    {
        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        return $type === CompetitionType::Team;
    }
}
