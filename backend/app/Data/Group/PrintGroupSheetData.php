<?php

namespace App\Data\Group;

final class PrintGroupSheetData
{
    /**
     * @param  array{id: int, name: string}  $tournament
     * @param  array{id: int, name: string, type: string}  $competition
     * @param  array{id: int, name: string}  $group
     * @param  list<array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}>  $participants
     * @param  list<array{
     *     game_id: int,
     *     order: int,
     *     group_round: int|null,
     *     group_match: int|null,
     *     side1_number: int|null,
     *     side1: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     side2_number: int|null,
     *     side2: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     referee_number: int|null,
     *     referee: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     best_of: int|null,
     *     sets_to_win: int|null
     * }>  $matches
     * @param  list<array{
     *     sheet_number: int,
     *     entry: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>},
     *     cells: list<array{opponent_number: int, type: 'self'|'match', game_id: int|null}>
     * }>  $matrix
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
        public string $sheetKind = 'generic',
        public array $matrix = [],
    ) {}

    /**
     * @return array{
     *     tournament: array{id: int, name: string},
     *     competition: array{id: int, name: string, type: string},
     *     group: array{id: int, name: string},
     *     best_of: int,
     *     sets_to_win: int,
     *     points_per_set: int,
     *     qualified_per_group: int,
     *     sheet_kind: string,
     *     participants: list<array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}>,
     *     matches: list<array{
     *         game_id: int,
     *         order: int,
     *         group_round: int|null,
     *         group_match: int|null,
     *         side1_number: int|null,
     *         side1: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *         side2_number: int|null,
     *         side2: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *         referee_number: int|null,
     *         referee: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *         best_of: int|null,
     *         sets_to_win: int|null
     *     }>,
     *     matrix: list<array{
     *         sheet_number: int,
     *         entry: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>},
     *         cells: list<array{opponent_number: int, type: 'self'|'match', game_id: int|null}>
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'tournament' => $this->tournament,
            'competition' => $this->competition,
            'group' => $this->group,
            'best_of' => $this->bestOf,
            'sets_to_win' => $this->setsToWin,
            'points_per_set' => $this->pointsPerSet,
            'qualified_per_group' => $this->qualifiedPerGroup,
            'sheet_kind' => $this->sheetKind,
            'participants' => $this->participants,
            'matches' => $this->matches,
            'matrix' => $this->matrix,
        ];
    }
}
