<?php

namespace Tests\Unit\Group;

use App\Support\Group\GroupSheetNumbering;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GroupSheetNumberingTest extends TestCase
{
    public function test_assigns_stable_sheet_numbers_by_competition_entry_id_asc(): void
    {
        $this->assertSame(
            [
                12 => 1,
                28 => 2,
                35 => 3,
                90 => 4,
            ],
            GroupSheetNumbering::forCompetitionEntryIds([90, 12, 35, 28]),
        );
    }

    public function test_numbering_is_independent_of_input_order(): void
    {
        $expected = [
            12 => 1,
            28 => 2,
            35 => 3,
            90 => 4,
        ];

        $this->assertSame(
            $expected,
            GroupSheetNumbering::forCompetitionEntryIds([12, 28, 35, 90]),
        );
        $this->assertSame(
            $expected,
            GroupSheetNumbering::forCompetitionEntryIds([90, 35, 28, 12]),
        );
        $this->assertSame(
            $expected,
            GroupSheetNumbering::forCompetitionEntryIds([28, 90, 12, 35]),
        );
    }

    public function test_empty_ids_return_empty_map(): void
    {
        $this->assertSame([], GroupSheetNumbering::forCompetitionEntryIds([]));
    }

    public function test_rejects_duplicate_competition_entry_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Los competition_entry_id de planilla no pueden repetirse.');

        GroupSheetNumbering::forCompetitionEntryIds([12, 28, 12]);
    }
}
