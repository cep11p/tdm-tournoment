<?php

namespace App\Data\Bracket;

final class GroupKnockoutDrawResult
{
    /**
     * @param  list<BracketDrawMatchData>  $matches
     */
    public function __construct(
        public int $bracketSize,
        public int $byesCount,
        public string $firstRoundLabel,
        public array $matches,
    ) {}
}
