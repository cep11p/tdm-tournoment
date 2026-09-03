<?php

namespace App\Support\Bracket;

final class BracketPositionSupport
{
    public static function destinationMatchNumber(int $sourceMatch): int
    {
        return (int) (floor(($sourceMatch - 1) / 2) + 1);
    }

    /**
     * @return 'entry1_id'|'entry2_id'
     */
    public static function winnerSlot(int $sourceMatch): string
    {
        return $sourceMatch % 2 === 1 ? 'entry1_id' : 'entry2_id';
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function sourceMatchNumbers(int $destinationMatch): array
    {
        return [
            ($destinationMatch * 2) - 1,
            $destinationMatch * 2,
        ];
    }
}
