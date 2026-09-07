<?php

namespace App\Enums;

enum CompetitionFinalStandingSource: string
{
    case Final = 'final';
    case ThirdPlacePlayoff = 'third_place_playoff';
    case Semifinal = 'semifinal';
    case Quarterfinal = 'quarterfinal';
    case RoundOf16 = 'round_of_16';
    case RoundOf32 = 'round_of_32';
    case PlayIn = 'play_in';
    case GroupStage = 'group_stage';
    case NotInDraw = 'not_in_draw';

    public function label(): string
    {
        return match ($this) {
            self::Final => 'Final',
            self::ThirdPlacePlayoff => 'Partido por el 3.er puesto',
            self::Semifinal => 'Semifinal',
            self::Quarterfinal => 'Cuartos de final',
            self::RoundOf16 => 'Octavos de final',
            self::RoundOf32 => 'Dieciseisavos',
            self::PlayIn => 'Play-in',
            self::GroupStage => 'Fase de grupos',
            self::NotInDraw => 'No participó del cuadro',
        };
    }
}
