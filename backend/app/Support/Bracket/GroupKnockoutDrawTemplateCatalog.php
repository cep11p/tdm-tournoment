<?php

namespace App\Support\Bracket;

use App\Data\Bracket\GroupKnockoutDrawTemplate;
use App\Data\Bracket\GroupKnockoutTemplateMatch;
use App\Data\Bracket\GroupKnockoutTemplateSlot;
use InvalidArgumentException;

final class GroupKnockoutDrawTemplateCatalog
{
    public const QUALIFIED_PER_GROUP = 3;

    /**
     * @var list<int>
     */
    public const SUPPORTED_GROUP_COUNTS = [3, 4, 5, 6, 7, 8, 9];

    public static function supports(int $groupCount): bool
    {
        return in_array($groupCount, self::SUPPORTED_GROUP_COUNTS, true);
    }

    public static function forGroupCount(int $groupCount): GroupKnockoutDrawTemplate
    {
        return match ($groupCount) {
            3 => self::threeGroups(),
            4 => self::fourGroups(),
            5 => self::fiveGroups(),
            6 => self::sixGroups(),
            7 => self::sevenGroups(),
            8 => self::eightGroups(),
            9 => self::nineGroups(),
            default => throw new InvalidArgumentException(
                sprintf(
                    'No hay plantilla oficial de llave para %d grupos con %d clasificados por grupo.',
                    $groupCount,
                    self::QUALIFIED_PER_GROUP,
                ),
            ),
        };
    }

    /**
     * @return list<GroupKnockoutDrawTemplate>
     */
    public static function all(): array
    {
        return array_map(
            fn (int $groupCount): GroupKnockoutDrawTemplate => self::forGroupCount($groupCount),
            self::SUPPORTED_GROUP_COUNTS,
        );
    }

    private static function threeGroups(): GroupKnockoutDrawTemplate
    {
        return self::template(3, 16, [
            self::match(1, self::slot(0, 1), null),
            self::match(2, self::slot(2, 3), self::slot(1, 3)),
            self::match(3, self::slot(1, 2), null),
            self::match(4, self::slot(2, 1), null),
            self::match(5, self::slot(2, 2), null),
            self::match(6, self::slot(0, 2), null),
            self::match(7, self::slot(0, 3), null),
            self::match(8, self::slot(1, 1), null),
        ]);
    }

    private static function fourGroups(): GroupKnockoutDrawTemplate
    {
        return self::template(4, 16, [
            self::match(1, self::slot(0, 1), null),
            self::match(2, self::slot(2, 2), self::slot(1, 3)),
            self::match(3, self::slot(2, 3), self::slot(1, 2)),
            self::match(4, self::slot(3, 1), null),
            self::match(5, self::slot(2, 1), null),
            self::match(6, self::slot(0, 2), self::slot(3, 3)),
            self::match(7, self::slot(0, 3), self::slot(3, 2)),
            self::match(8, self::slot(1, 1), null),
        ]);
    }

    private static function fiveGroups(): GroupKnockoutDrawTemplate
    {
        return self::template(5, 16, [
            self::match(1, self::slot(0, 1), null),
            self::match(2, self::slot(2, 2), self::slot(1, 3)),
            self::match(3, self::slot(4, 1), self::slot(1, 2)),
            self::match(4, self::slot(0, 3), self::slot(3, 1)),
            self::match(5, self::slot(2, 1), self::slot(4, 3)),
            self::match(6, self::slot(0, 2), self::slot(3, 2)),
            self::match(7, self::slot(4, 2), self::slot(3, 3)),
            self::match(8, self::slot(2, 3), self::slot(1, 1)),
        ]);
    }

    private static function sixGroups(): GroupKnockoutDrawTemplate
    {
        return self::template(6, 32, [
            self::match(1, self::slot(0, 1), null),
            self::match(2, self::slot(3, 3), self::slot(4, 3)),
            self::match(3, self::slot(5, 2), null),
            self::match(4, self::slot(2, 2), null),
            self::match(5, self::slot(4, 1), null),
            self::match(6, self::slot(1, 2), null),
            self::match(7, self::slot(0, 3), null),
            self::match(8, self::slot(3, 1), null),
            self::match(9, self::slot(2, 1), null),
            self::match(10, self::slot(1, 3), null),
            self::match(11, self::slot(0, 2), null),
            self::match(12, self::slot(5, 1), null),
            self::match(13, self::slot(3, 2), null),
            self::match(14, self::slot(4, 2), null),
            self::match(15, self::slot(2, 3), self::slot(5, 3)),
            self::match(16, self::slot(1, 1), null),
        ]);
    }

    private static function sevenGroups(): GroupKnockoutDrawTemplate
    {
        return self::template(7, 32, [
            self::match(1, self::slot(0, 1), null),
            self::match(2, self::slot(3, 3), self::slot(4, 3)),
            self::match(3, self::slot(5, 2), null),
            self::match(4, self::slot(6, 2), null),
            self::match(5, self::slot(4, 1), null),
            self::match(6, self::slot(1, 2), self::slot(6, 3)),
            self::match(7, self::slot(2, 2), self::slot(0, 3)),
            self::match(8, self::slot(3, 1), null),
            self::match(9, self::slot(2, 1), null),
            self::match(10, self::slot(4, 2), self::slot(1, 3)),
            self::match(11, self::slot(0, 2), null),
            self::match(12, self::slot(5, 1), null),
            self::match(13, self::slot(6, 1), null),
            self::match(14, self::slot(3, 2), null),
            self::match(15, self::slot(5, 3), self::slot(2, 3)),
            self::match(16, self::slot(1, 1), null),
        ]);
    }

    private static function eightGroups(): GroupKnockoutDrawTemplate
    {
        return self::template(8, 32, [
            self::match(1, self::slot(0, 1), null),
            self::match(2, self::slot(6, 2), self::slot(3, 3)),
            self::match(3, self::slot(5, 2), self::slot(4, 3)),
            self::match(4, self::slot(7, 1), null),
            self::match(5, self::slot(4, 1), null),
            self::match(6, self::slot(1, 2), self::slot(7, 3)),
            self::match(7, self::slot(2, 2), self::slot(0, 3)),
            self::match(8, self::slot(3, 1), null),
            self::match(9, self::slot(2, 1), null),
            self::match(10, self::slot(4, 2), self::slot(1, 3)),
            self::match(11, self::slot(0, 2), self::slot(6, 3)),
            self::match(12, self::slot(5, 1), null),
            self::match(13, self::slot(6, 1), null),
            self::match(14, self::slot(3, 2), self::slot(2, 3)),
            self::match(15, self::slot(7, 2), self::slot(5, 3)),
            self::match(16, self::slot(1, 1), null),
        ]);
    }

    private static function nineGroups(): GroupKnockoutDrawTemplate
    {
        return self::template(9, 32, [
            self::match(1, self::slot(0, 1), null),
            self::match(2, self::slot(6, 2), self::slot(4, 3)),
            self::match(3, self::slot(8, 1), self::slot(3, 3)),
            self::match(4, self::slot(7, 1), self::slot(1, 3)),
            self::match(5, self::slot(4, 1), null),
            self::match(6, self::slot(1, 2), self::slot(2, 2)),
            self::match(7, self::slot(5, 2), self::slot(0, 3)),
            self::match(8, self::slot(3, 1), null),
            self::match(9, self::slot(2, 1), null),
            self::match(10, self::slot(3, 2), self::slot(8, 3)),
            self::match(11, self::slot(0, 2), self::slot(4, 2)),
            self::match(12, self::slot(5, 1), self::slot(7, 3)),
            self::match(13, self::slot(6, 1), self::slot(2, 3)),
            self::match(14, self::slot(7, 2), self::slot(8, 2)),
            self::match(15, self::slot(5, 3), self::slot(6, 3)),
            self::match(16, self::slot(1, 1), null),
        ]);
    }

    /**
     * @param  list<GroupKnockoutTemplateMatch>  $matches
     */
    private static function template(int $groupCount, int $bracketSize, array $matches): GroupKnockoutDrawTemplate
    {
        return new GroupKnockoutDrawTemplate(
            groupCount: $groupCount,
            qualifiedPerGroup: self::QUALIFIED_PER_GROUP,
            bracketSize: $bracketSize,
            matches: $matches,
        );
    }

    private static function match(
        int $bracketMatch,
        GroupKnockoutTemplateSlot $side1,
        ?GroupKnockoutTemplateSlot $side2,
    ): GroupKnockoutTemplateMatch {
        return new GroupKnockoutTemplateMatch(
            bracketMatch: $bracketMatch,
            side1: $side1,
            side2: $side2,
        );
    }

    private static function slot(int $groupIndex, int $groupPosition): GroupKnockoutTemplateSlot
    {
        return new GroupKnockoutTemplateSlot(
            groupIndex: $groupIndex,
            groupPosition: $groupPosition,
        );
    }
}
