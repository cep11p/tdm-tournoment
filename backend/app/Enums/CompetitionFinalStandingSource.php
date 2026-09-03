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
}
