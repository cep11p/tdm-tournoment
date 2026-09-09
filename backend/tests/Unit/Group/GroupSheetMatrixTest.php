<?php

namespace Tests\Unit\Group;

use App\Support\Group\GroupSheetMatrix;
use PHPUnit\Framework\TestCase;

class GroupSheetMatrixTest extends TestCase
{
    public function test_builds_self_diagonal_and_operational_match_cells(): void
    {
        $participants = [
            $this->participant(1, 12, 'Carlos'),
            $this->participant(2, 28, 'Ana'),
            $this->participant(3, 35, 'Juan'),
        ];
        $matches = [
            ['game_id' => 101, 'side1_number' => 1, 'side2_number' => 3],
            ['game_id' => 102, 'side1_number' => 1, 'side2_number' => 2],
            ['game_id' => 103, 'side1_number' => 2, 'side2_number' => 3],
        ];

        $matrix = GroupSheetMatrix::build($participants, $matches);

        $this->assertCount(3, $matrix);
        $this->assertSame([1, 2, 3], array_column($matrix, 'sheet_number'));
        $this->assertSame($participants[0], $matrix[0]['entry']);

        $this->assertSame('self', $matrix[0]['cells'][0]['type']);
        $this->assertSame(1, $matrix[0]['cells'][0]['opponent_number']);
        $this->assertNull($matrix[0]['cells'][0]['game_id']);

        $this->assertSame('match', $matrix[0]['cells'][1]['type']);
        $this->assertSame(2, $matrix[0]['cells'][1]['opponent_number']);
        $this->assertSame(102, $matrix[0]['cells'][1]['game_id']);

        $this->assertSame('match', $matrix[0]['cells'][2]['type']);
        $this->assertSame(3, $matrix[0]['cells'][2]['opponent_number']);
        $this->assertSame(101, $matrix[0]['cells'][2]['game_id']);

        $this->assertSame('self', $matrix[1]['cells'][1]['type']);
        $this->assertSame('self', $matrix[2]['cells'][2]['type']);

        foreach ($matrix as $row) {
            foreach ($row['cells'] as $cell) {
                $this->assertArrayNotHasKey('sets', $cell);
                $this->assertArrayNotHasKey('score', $cell);
                $this->assertArrayNotHasKey('winner_entry_id', $cell);
            }
        }
    }

    /**
     * @return array{competition_entry_id: int, sheet_number: int, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}
     */
    private function participant(int $sheetNumber, int $entryId, string $name): array
    {
        return [
            'competition_entry_id' => $entryId,
            'sheet_number' => $sheetNumber,
            'display_name' => $name,
            'members' => [],
        ];
    }
}
