<?php

namespace Tests\Unit\Bracket;

use App\Support\Bracket\BracketPositionSupport;
use Tests\TestCase;

class BracketPositionSupportTest extends TestCase
{
    public function test_source_match_numbers_for_destination_one(): void
    {
        $this->assertSame([1, 2], BracketPositionSupport::sourceMatchNumbers(1));
    }

    public function test_source_match_numbers_for_destination_two(): void
    {
        $this->assertSame([3, 4], BracketPositionSupport::sourceMatchNumbers(2));
    }

    public function test_source_match_numbers_for_destination_three(): void
    {
        $this->assertSame([5, 6], BracketPositionSupport::sourceMatchNumbers(3));
    }

    public function test_source_and_destination_are_inverses(): void
    {
        foreach ([1, 2, 3, 4, 8, 16] as $destination) {
            [$source1, $source2] = BracketPositionSupport::sourceMatchNumbers($destination);

            $this->assertSame($destination, BracketPositionSupport::destinationMatchNumber($source1));
            $this->assertSame($destination, BracketPositionSupport::destinationMatchNumber($source2));
            $this->assertSame('entry1_id', BracketPositionSupport::winnerSlot($source1));
            $this->assertSame('entry2_id', BracketPositionSupport::winnerSlot($source2));
        }
    }
}
