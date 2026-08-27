<?php

namespace App\Enums;

enum CompetitionEntryStatus: string
{
    case Active = 'active';
    case Withdrawn = 'withdrawn';
    case Disqualified = 'disqualified';
}
