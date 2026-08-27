<?php

namespace App\Data\Bracket;

final class BracketDrawMatchData
{
    public function __construct(
        public int $bracketMatch,
        public int $entry1Id,
        public ?int $entry2Id,
        public bool $isBye,
    ) {}
}
