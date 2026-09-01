<?php

namespace App\Data\Group;

final class PrintGroupSheetData
{
    /**
     * @param  array{id: int, name: string}  $tournament
     * @param  array{id: int, name: string, type: string}  $competition
     * @param  array{id: int, name: string}  $group
     * @param  list<array{competition_entry_id: int, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}>  $participants
     * @param  list<array{
     *     game_id: int,
     *     order: int,
     *     group_round: int|null,
     *     group_match: int|null,
     *     side1: array{competition_entry_id: int, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     side2: array{competition_entry_id: int, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     referee: array{competition_entry_id: int, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     best_of: int|null,
     *     sets_to_win: int|null
     * }>  $matches
     */
    public function __construct(
        public array $tournament,
        public array $competition,
        public array $group,
        public int $bestOf,
        public int $setsToWin,
        public int $pointsPerSet,
        public int $qualifiedPerGroup,
        public array $participants,
        public array $matches,
    ) {}
}
