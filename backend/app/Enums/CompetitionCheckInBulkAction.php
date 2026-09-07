<?php

namespace App\Enums;

enum CompetitionCheckInBulkAction: string
{
    case MarkAllPresent = 'mark_all_present';
    case ClearAll = 'clear_all';
}
