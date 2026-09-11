<?php

namespace Tests\Unit\Group;

use App\Support\Group\GroupRoundRobinSlotAllocator;
use PHPUnit\Framework\TestCase;

class GroupRoundRobinSlotAllocatorTest extends TestCase
{
    public function test_uses_ideal_slot_when_free(): void
    {
        $occupied = [];

        $allocated = GroupRoundRobinSlotAllocator::allocate(1, 2, $occupied, officialMaxRound: 3);

        $this->assertSame(['group_round' => 1, 'group_match' => 2], $allocated);
        $this->assertTrue($occupied[GroupRoundRobinSlotAllocator::key(1, 2)]);
    }

    public function test_appends_after_official_schedule_when_ideal_slot_is_occupied(): void
    {
        $occupied = [
            GroupRoundRobinSlotAllocator::key(1, 1) => true,
            GroupRoundRobinSlotAllocator::key(2, 1) => true,
            GroupRoundRobinSlotAllocator::key(3, 1) => true,
        ];

        $twoVsFour = GroupRoundRobinSlotAllocator::allocate(1, 2, $occupied, officialMaxRound: 3);
        $threeVsFour = GroupRoundRobinSlotAllocator::allocate(2, 2, $occupied, officialMaxRound: 3);
        $oneVsFour = GroupRoundRobinSlotAllocator::allocate(3, 1, $occupied, officialMaxRound: 3);

        $this->assertSame(['group_round' => 1, 'group_match' => 2], $twoVsFour);
        $this->assertSame(['group_round' => 2, 'group_match' => 2], $threeVsFour);
        $this->assertSame(['group_round' => 4, 'group_match' => 1], $oneVsFour);
    }

    public function test_fills_appended_round_sequentially_when_multiple_slots_collide(): void
    {
        $occupied = [
            GroupRoundRobinSlotAllocator::key(1, 1) => true,
            GroupRoundRobinSlotAllocator::key(2, 1) => true,
            GroupRoundRobinSlotAllocator::key(3, 1) => true,
            GroupRoundRobinSlotAllocator::key(3, 2) => true,
        ];

        $first = GroupRoundRobinSlotAllocator::allocate(3, 1, $occupied, officialMaxRound: 3);
        $second = GroupRoundRobinSlotAllocator::allocate(3, 2, $occupied, officialMaxRound: 3);

        $this->assertSame(['group_round' => 4, 'group_match' => 1], $first);
        $this->assertSame(['group_round' => 4, 'group_match' => 2], $second);
    }
}
