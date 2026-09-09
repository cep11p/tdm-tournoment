<?php

namespace App\Support\Group;

use App\Data\Group\GroupSheetFixture;

final class GroupSheetPlayingOrder
{
    /**
     * @var list<int>
     */
    public const SUPPORTED_SIZES = [3, 4, 5];

    public static function supports(int $size): bool
    {
        return in_array($size, self::SUPPORTED_SIZES, true);
    }

    /**
     * Orden operativo exacto de las planillas G3/G4/G5.
     *
     * Cada fixture conserva la orientación de lados de la hoja
     * (por ejemplo 5-3 no se normaliza a 3-5).
     *
     * @return list<GroupSheetFixture>
     */
    public static function forSize(int $size): array
    {
        return match ($size) {
            3 => self::g3(),
            4 => self::g4(),
            5 => self::g5(),
            default => throw GroupSheetUnsupportedSizeException::forSize($size),
        };
    }

    /**
     * @return list<GroupSheetFixture>
     */
    private static function g3(): array
    {
        return [
            self::fixture(1, 1, 1, 3),
            self::fixture(2, 1, 1, 2),
            self::fixture(3, 1, 2, 3),
        ];
    }

    /**
     * @return list<GroupSheetFixture>
     */
    private static function g4(): array
    {
        return [
            self::fixture(1, 1, 1, 3),
            self::fixture(1, 2, 2, 4),
            self::fixture(2, 1, 1, 2),
            self::fixture(2, 2, 3, 4),
            self::fixture(3, 1, 1, 4),
            self::fixture(3, 2, 2, 3),
        ];
    }

    /**
     * @return list<GroupSheetFixture>
     */
    private static function g5(): array
    {
        return [
            self::fixture(1, 1, 2, 5),
            self::fixture(1, 2, 3, 4),
            self::fixture(2, 1, 1, 5),
            self::fixture(2, 2, 2, 3),
            self::fixture(3, 1, 1, 4),
            self::fixture(3, 2, 5, 3),
            self::fixture(4, 1, 1, 3),
            self::fixture(4, 2, 4, 2),
            self::fixture(5, 1, 1, 2),
            self::fixture(5, 2, 4, 5),
        ];
    }

    private static function fixture(int $groupRound, int $groupMatch, int $side1, int $side2): GroupSheetFixture
    {
        return new GroupSheetFixture($groupRound, $groupMatch, $side1, $side2);
    }
}
