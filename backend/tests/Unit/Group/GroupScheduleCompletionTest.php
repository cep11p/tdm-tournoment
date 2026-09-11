<?php

namespace Tests\Unit\Group;

use App\Actions\Group\PersistGroupEntryAction;
use App\Support\Group\GroupScheduleCompletion;
use Tests\TestCase;

class GroupScheduleCompletionTest extends TestCase
{
    public function test_expected_pair_count_matches_round_robin_without_byes(): void
    {
        $this->assertSame(0, GroupScheduleCompletion::expectedPairCount(0));
        $this->assertSame(0, GroupScheduleCompletion::expectedPairCount(1));
        $this->assertSame(1, GroupScheduleCompletion::expectedPairCount(2));
        $this->assertSame(3, GroupScheduleCompletion::expectedPairCount(3));
        $this->assertSame(6, GroupScheduleCompletion::expectedPairCount(4));
        $this->assertSame(10, GroupScheduleCompletion::expectedPairCount(5));
    }

    public function test_empty_group_is_not_complete(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $group = $context->createGroup($competition, 'Grupo D');

        $this->assertFalse(GroupScheduleCompletion::hasCompleteGamesSchedule($group));
        $this->assertFalse(GroupScheduleCompletion::hasOpenGames($group));
    }

    public function test_single_entry_group_is_not_complete(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $context->registerPlayer($competition, $player);
        $group = $context->createGroupWithPlayers($competition, [$player]);

        $this->assertFalse(GroupScheduleCompletion::hasCompleteGamesSchedule($group));
        $this->assertSame(0, $group->games()->count());
    }

    public function test_g3_with_full_fixture_is_complete_even_if_pending(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $this->assertTrue(GroupScheduleCompletion::hasCompleteGamesSchedule($group));
        $this->assertTrue(GroupScheduleCompletion::hasOpenGames($group));
    }

    public function test_incomplete_g4_fixture_is_not_complete(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $entries = [];
        foreach ($players as $player) {
            $entries[] = $context->registerPlayer($competition, $player);
        }
        $group = $context->createGroupWithEntries($competition, array_slice($entries, 0, 3));
        $context->generateRoundRobin($group)->assertCreated();
        app(PersistGroupEntryAction::class)($group, $entries[3]);

        $this->assertSame(4, $group->groupEntries()->count());
        $this->assertSame(3, $group->games()->count());
        $this->assertFalse(GroupScheduleCompletion::hasCompleteGamesSchedule($group));
    }
}
