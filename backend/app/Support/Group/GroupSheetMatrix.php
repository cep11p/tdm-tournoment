<?php

namespace App\Support\Group;

final class GroupSheetMatrix
{
    /**
     * Matriz operativa N×N: diagonal marcada como self, el resto como match vacío.
     *
     * @param  list<array{sheet_number: int, competition_entry_id: int, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}>  $participants
     * @param  list<array{game_id: int, side1_number: int|null, side2_number: int|null}>  $matches
     * @return list<array{
     *     sheet_number: int,
     *     entry: array{sheet_number: int, competition_entry_id: int, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>},
     *     cells: list<array{opponent_number: int, type: 'self'|'match', game_id: int|null}>
     * }>
     */
    public static function build(array $participants, array $matches): array
    {
        $gameIdByPair = [];

        foreach ($matches as $match) {
            $side1Number = $match['side1_number'] ?? null;
            $side2Number = $match['side2_number'] ?? null;

            if (! is_int($side1Number) || ! is_int($side2Number)) {
                continue;
            }

            $gameIdByPair[self::unorderedPairKey($side1Number, $side2Number)] = (int) $match['game_id'];
        }

        $matrix = [];

        foreach ($participants as $participant) {
            $sheetNumber = (int) $participant['sheet_number'];
            $cells = [];

            foreach ($participants as $opponent) {
                $opponentNumber = (int) $opponent['sheet_number'];

                if ($opponentNumber === $sheetNumber) {
                    $cells[] = [
                        'opponent_number' => $opponentNumber,
                        'type' => 'self',
                        'game_id' => null,
                    ];

                    continue;
                }

                $cells[] = [
                    'opponent_number' => $opponentNumber,
                    'type' => 'match',
                    'game_id' => $gameIdByPair[self::unorderedPairKey($sheetNumber, $opponentNumber)] ?? null,
                ];
            }

            $matrix[] = [
                'sheet_number' => $sheetNumber,
                'entry' => $participant,
                'cells' => $cells,
            ];
        }

        return $matrix;
    }

    private static function unorderedPairKey(int $left, int $right): string
    {
        $pair = [$left, $right];
        sort($pair);

        return sprintf('%d-%d', $pair[0], $pair[1]);
    }
}
