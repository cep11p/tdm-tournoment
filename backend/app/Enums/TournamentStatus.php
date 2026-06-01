<?php

namespace App\Enums;

enum TournamentStatus: string
{
    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Finished = 'finished';
}
