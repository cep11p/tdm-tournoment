<?php

namespace App\Data\Group;

final class GroupSheetFixture
{
    public function __construct(
        public int $groupRound,
        public int $groupMatch,
        public int $side1,
        public int $side2,
    ) {}
}
