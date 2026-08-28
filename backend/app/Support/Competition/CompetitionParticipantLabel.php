<?php

namespace App\Support\Competition;

use App\Enums\CompetitionType;
use App\Models\Competition;

final class CompetitionParticipantLabel
{
    public static function plural(Competition $competition): string
    {
        return self::isDoubles($competition) ? 'parejas' : 'jugadores';
    }

    public static function singular(Competition $competition): string
    {
        return self::isDoubles($competition) ? 'pareja' : 'jugador';
    }

    public static function registeredNone(Competition $competition): string
    {
        if (self::isDoubles($competition)) {
            return 'La competencia no tiene parejas inscriptas.';
        }

        return 'La competencia no tiene jugadores inscriptos.';
    }

    public static function minimumForGroups(Competition $competition): string
    {
        if (self::isDoubles($competition)) {
            return 'Se requieren al menos 2 parejas inscriptas para generar grupos.';
        }

        return 'Se requieren al menos 2 jugadores inscriptos para generar grupos.';
    }

    public static function exceedsGroupCount(Competition $competition): string
    {
        if (self::isDoubles($competition)) {
            return 'La cantidad de grupos no puede ser mayor que la cantidad de parejas inscriptas.';
        }

        return 'La cantidad de grupos no puede ser mayor que la cantidad de jugadores inscriptos.';
    }

    private static function isDoubles(Competition $competition): bool
    {
        return $competition->type === CompetitionType::Doubles;
    }
}
