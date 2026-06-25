<?php

namespace App\Enums;

enum GroupPlayerStatusReason: string
{
    case Personal = 'personal';
    case Injury = 'injury';
    case NoShow = 'no_show';
    case OrganizerDecision = 'organizer_decision';
    case Other = 'other';
}
