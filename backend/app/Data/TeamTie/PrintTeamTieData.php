<?php

namespace App\Data\TeamTie;

final class PrintTeamTieData
{
    /**
     * @param  array{id: int, name: string}  $tournament
     * @param  array{id: int, name: string, type: string}  $competition
     * @param  array{id: int, context_label: string, status: string, is_bye: bool, victories_required: int}  $teamTie
     * @param  array{competition_entry_id: int, display_name: string}|null  $side1
     * @param  array{competition_entry_id: int, display_name: string}|null  $side2
     * @param  array{side1: int, side2: int}  $score
     * @param  array{competition_entry_id: int, display_name: string}|null  $winner
     * @param  array{name: string, slots_count: int}  $format
     * @param  list<array{
     *     slot_order: int,
     *     type: string,
     *     label: string,
     *     status: string,
     *     official: bool,
     *     lineup_complete: bool,
     *     side1: array{players: list<array{id: int|null, name: string}>},
     *     side2: array{players: list<array{id: int|null, name: string}>},
     *     winner_side: int|null
     * }>  $rubbers
     */
    public function __construct(
        public array $tournament,
        public array $competition,
        public array $teamTie,
        public ?array $side1,
        public ?array $side2,
        public array $score,
        public ?array $winner,
        public array $format,
        public array $rubbers,
    ) {}

    /**
     * @return array{
     *     tournament: array{id: int, name: string},
     *     competition: array{id: int, name: string, type: string},
     *     team_tie: array{id: int, context_label: string, status: string, is_bye: bool, victories_required: int},
     *     side1: array{competition_entry_id: int, display_name: string}|null,
     *     side2: array{competition_entry_id: int, display_name: string}|null,
     *     score: array{side1: int, side2: int},
     *     winner: array{competition_entry_id: int, display_name: string}|null,
     *     format: array{name: string, slots_count: int},
     *     rubbers: list<array{
     *         slot_order: int,
     *         type: string,
     *         label: string,
     *         status: string,
     *         official: bool,
     *         lineup_complete: bool,
     *         side1: array{players: list<array{id: int|null, name: string}>},
     *         side2: array{players: list<array{id: int|null, name: string}>},
     *         winner_side: int|null
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'tournament' => $this->tournament,
            'competition' => $this->competition,
            'team_tie' => $this->teamTie,
            'side1' => $this->side1,
            'side2' => $this->side2,
            'score' => $this->score,
            'winner' => $this->winner,
            'format' => $this->format,
            'rubbers' => $this->rubbers,
        ];
    }
}
