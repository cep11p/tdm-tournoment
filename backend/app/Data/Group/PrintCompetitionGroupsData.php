<?php

namespace App\Data\Group;

final class PrintCompetitionGroupsData
{
    /**
     * @param  array{id: int, name: string}  $tournament
     * @param  array{id: int, name: string, type: string}  $competition
     * @param  list<PrintGroupSheetData>  $sheets
     */
    public function __construct(
        public array $tournament,
        public array $competition,
        public int $groupsCount,
        public array $sheets,
    ) {}
}
