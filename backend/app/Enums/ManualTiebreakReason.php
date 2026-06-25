<?php

namespace App\Enums;

enum ManualTiebreakReason: string
{
    case Draw = 'draw';
    case OrganizerDecision = 'organizer_decision';
    case Agreement = 'agreement';
    case Other = 'other';
}
