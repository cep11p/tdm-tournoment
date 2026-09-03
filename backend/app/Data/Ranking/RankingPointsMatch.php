<?php

namespace App\Data\Ranking;

use App\Models\RankingRule;

final class RankingPointsMatch
{
    public function __construct(
        public ?RankingRule $rule,
        public int $points,
    ) {}
}
