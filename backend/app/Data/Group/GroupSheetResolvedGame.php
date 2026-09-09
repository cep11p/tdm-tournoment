<?php

namespace App\Data\Group;

use App\Models\CompetitionEntry;
use App\Models\Game;

final class GroupSheetResolvedGame
{
    public function __construct(
        public Game $game,
        public GroupSheetFixture $fixture,
        public CompetitionEntry $side1,
        public CompetitionEntry $side2,
    ) {}
}
