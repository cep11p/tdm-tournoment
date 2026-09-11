<?php

namespace Tests\Unit\Bracket;

use App\Support\Bracket\BracketSupport;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BracketSupportSeedPlacementTest extends TestCase
{
    public function test_seed_positions_for_power_of_two_sizes(): void
    {
        $this->assertSame([1, 2], BracketSupport::seedPositions(2));
        $this->assertSame([1, 4, 2, 3], BracketSupport::seedPositions(4));
        $this->assertSame([1, 8, 4, 5, 2, 7, 3, 6], BracketSupport::seedPositions(8));
        $this->assertSame(
            [1, 16, 8, 9, 4, 13, 5, 12, 2, 15, 7, 10, 3, 14, 6, 11],
            BracketSupport::seedPositions(16),
        );
        $this->assertSame(
            [
                1, 32, 16, 17, 8, 25, 9, 24, 4, 29, 13, 20, 5, 28, 12, 21,
                2, 31, 15, 18, 7, 26, 10, 23, 3, 30, 14, 19, 6, 27, 11, 22,
            ],
            BracketSupport::seedPositions(32),
        );
    }

    #[DataProvider('halfAndQuarterSizesProvider')]
    public function test_seed_one_and_two_are_in_opposite_halves(int $bracketSize): void
    {
        $indexBySeed = array_flip(BracketSupport::seedPositions($bracketSize));
        $half = (int) ($bracketSize / 2);

        $this->assertLessThan($half, $indexBySeed[1]);
        $this->assertGreaterThanOrEqual($half, $indexBySeed[2]);
    }

    #[DataProvider('halfAndQuarterSizesProvider')]
    public function test_seeds_one_through_four_occupy_distinct_quarters(int $bracketSize): void
    {
        $indexBySeed = array_flip(BracketSupport::seedPositions($bracketSize));
        $quarter = (int) ($bracketSize / 4);

        $quarters = [];

        foreach ([1, 2, 3, 4] as $seed) {
            $quarters[$seed] = intdiv($indexBySeed[$seed], $quarter);
        }

        $this->assertCount(4, array_unique($quarters));
    }

    #[DataProvider('powerOfTwoSizesProvider')]
    public function test_each_seed_appears_exactly_once(int $bracketSize): void
    {
        $positions = BracketSupport::seedPositions($bracketSize);
        $sorted = $positions;
        sort($sorted);

        $this->assertCount($bracketSize, $positions);
        $this->assertSame(range(1, $bracketSize), $sorted);
    }

    public function test_counts_from_two_to_thirty_two_do_not_generate_bye_versus_bye(): void
    {
        for ($participantCount = 2; $participantCount <= 32; $participantCount++) {
            $slots = BracketSupport::firstRoundSlots(range(1, $participantCount));

            foreach ($slots as $slot) {
                $this->assertNotNull($slot['entry1Id']);

                if ($slot['isBye']) {
                    $this->assertNull($slot['entry2Id']);
                } else {
                    $this->assertNotNull($slot['entry2Id']);
                }
            }
        }
    }

    public function test_bye_count_is_bracket_size_minus_participant_count(): void
    {
        foreach ([2, 3, 5, 6, 7, 8, 9, 13, 17, 24, 32] as $participantCount) {
            $slots = BracketSupport::firstRoundSlots(range(1, $participantCount));
            $bracketSize = BracketSupport::nextPowerOfTwo($participantCount);
            $byeCount = count(array_filter(
                $slots,
                fn (array $slot): bool => $slot['isBye'],
            ));

            $this->assertCount((int) ($bracketSize / 2), $slots);
            $this->assertSame($bracketSize - $participantCount, $byeCount);
        }
    }

    public function test_five_entries_place_byes_on_seeds_one_two_and_three(): void
    {
        $this->assertSame(
            [
                ['bracketMatch' => 1, 'entry1Id' => 101, 'entry2Id' => null, 'isBye' => true],
                ['bracketMatch' => 2, 'entry1Id' => 104, 'entry2Id' => 105, 'isBye' => false],
                ['bracketMatch' => 3, 'entry1Id' => 102, 'entry2Id' => null, 'isBye' => true],
                ['bracketMatch' => 4, 'entry1Id' => 103, 'entry2Id' => null, 'isBye' => true],
            ],
            BracketSupport::firstRoundSlots([101, 102, 103, 104, 105]),
        );
    }

    public function test_rejects_invalid_bracket_sizes(): void
    {
        $this->expectException(ValidationException::class);

        BracketSupport::seedPositions(6);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function powerOfTwoSizesProvider(): array
    {
        return [
            'size 2' => [2],
            'size 4' => [4],
            'size 8' => [8],
            'size 16' => [16],
            'size 32' => [32],
        ];
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function halfAndQuarterSizesProvider(): array
    {
        return [
            'size 8' => [8],
            'size 16' => [16],
            'size 32' => [32],
        ];
    }
}
