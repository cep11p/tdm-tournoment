<?php

namespace App\Data\Bracket;

final class BracketDrawMatchData
{
    public function __construct(
        public int $bracketMatch,
        public int $player1Id,
        public ?int $player2Id,
        public bool $isBye,
    ) {}
}
