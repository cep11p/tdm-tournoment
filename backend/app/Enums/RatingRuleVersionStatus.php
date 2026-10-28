<?php

namespace App\Enums;

enum RatingRuleVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
