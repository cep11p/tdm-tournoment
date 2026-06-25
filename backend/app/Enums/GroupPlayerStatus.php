<?php

namespace App\Enums;

enum GroupPlayerStatus: string
{
    case Active = 'active';
    case Withdrawn = 'withdrawn';
    case Disqualified = 'disqualified';
}
