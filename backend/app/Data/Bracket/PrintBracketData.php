<?php

namespace App\Data\Bracket;

final class PrintBracketData
{
    /**
     * @param  array{id: int, name: string}  $tournament
     * @param  array{id: int, name: string, type: string}  $competition
     * @param  array{id: int, name: string, bracket_size: int, byes_count: int, rounds_count: int}  $bracket
     * @param  list<array{number: int, label: string, matches: list<array<string, mixed>>}>  $rounds
     * @param  array<string, mixed>|null  $thirdPlace
     * @param  array{competition_entry_id: int, display_name: string}|null  $champion
     */
    public function __construct(
        public array $tournament,
        public array $competition,
        public array $bracket,
        public array $rounds,
        public ?array $thirdPlace,
        public ?array $champion,
    ) {}

    /**
     * @return array{
     *     tournament: array{id: int, name: string},
     *     competition: array{id: int, name: string, type: string},
     *     bracket: array{id: int, name: string, bracket_size: int, byes_count: int, rounds_count: int},
     *     rounds: list<array{number: int, label: string, matches: list<array<string, mixed>>}>,
     *     third_place: array<string, mixed>|null,
     *     champion: array{competition_entry_id: int, display_name: string}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'tournament' => $this->tournament,
            'competition' => $this->competition,
            'bracket' => $this->bracket,
            'rounds' => $this->rounds,
            'third_place' => $this->thirdPlace,
            'champion' => $this->champion,
        ];
    }
}
