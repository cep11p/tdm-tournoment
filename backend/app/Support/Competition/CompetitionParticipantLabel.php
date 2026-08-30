<?php

namespace App\Support\Competition;

use App\Enums\CompetitionType;
use App\Models\Competition;

final class CompetitionParticipantLabel
{
    public static function plural(Competition $competition): string
    {
        return match (self::type($competition)) {
            CompetitionType::Doubles => 'parejas',
            CompetitionType::Team => 'equipos',
            default => 'jugadores',
        };
    }

    public static function singular(Competition $competition): string
    {
        return match (self::type($competition)) {
            CompetitionType::Doubles => 'pareja',
            CompetitionType::Team => 'equipo',
            default => 'jugador',
        };
    }

    public static function registeredNone(Competition $competition): string
    {
        return match (self::type($competition)) {
            CompetitionType::Doubles => 'La competencia no tiene parejas inscriptas.',
            CompetitionType::Team => 'La competencia no tiene equipos inscriptos.',
            default => 'La competencia no tiene jugadores inscriptos.',
        };
    }

    public static function minimumForGroups(Competition $competition): string
    {
        $label = self::plural($competition);

        return "Se requieren al menos 2 {$label} inscriptos para generar grupos.";
    }

    public static function exceedsGroupCount(Competition $competition): string
    {
        $label = self::plural($competition);

        return "La cantidad de grupos no puede ser mayor que la cantidad de {$label} inscriptos.";
    }

    private static function type(Competition $competition): CompetitionType
    {
        return $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);
    }
}
