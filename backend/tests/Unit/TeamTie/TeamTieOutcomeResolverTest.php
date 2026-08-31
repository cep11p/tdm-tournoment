<?php

namespace Tests\Unit\TeamTie;

use App\Enums\GameStatus;
use App\Models\TeamTie;
use App\Models\TeamTieGame;
use App\Support\TeamTie\TeamTieOutcomeResolver;
use Tests\TestCase;

class TeamTieOutcomeResolverTest extends TestCase
{
    public function test_open_tie_returns_zero_score(): void
    {
        $teamTie = $this->createScheduledTeamTie();

        $outcome = TeamTieOutcomeResolver::resolve($teamTie);

        $this->assertSame(0, $outcome['entry1_wins']);
        $this->assertSame(0, $outcome['entry2_wins']);
        $this->assertFalse($outcome['is_decided']);
        $this->assertNull($outcome['winner_entry_id']);
        $this->assertNull($outcome['clinch_slot_order']);
        $this->assertSame([], $outcome['slots_to_mark_not_needed']);
    }

    public function test_one_win_keeps_tie_open(): void
    {
        $teamTie = $this->createScheduledTeamTie();
        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);

        $outcome = TeamTieOutcomeResolver::resolve($teamTie->fresh());

        $this->assertSame(1, $outcome['entry1_wins']);
        $this->assertSame(0, $outcome['entry2_wins']);
        $this->assertFalse($outcome['is_decided']);
        $this->assertSame(1, $outcome['rubbers_counting']);
    }

    public function test_two_two_keeps_tie_open(): void
    {
        $teamTie = $this->createScheduledTeamTie();

        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 2, (int) $teamTie->entry2_id);
        $this->finishRubberSlot($teamTie, 3, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 4, (int) $teamTie->entry2_id);

        $outcome = TeamTieOutcomeResolver::resolve($teamTie->fresh());

        $this->assertSame(2, $outcome['entry1_wins']);
        $this->assertSame(2, $outcome['entry2_wins']);
        $this->assertFalse($outcome['is_decided']);
    }

    public function test_three_zero_clinches_at_slot_three(): void
    {
        $teamTie = $this->createScheduledTeamTie();

        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 2, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 3, (int) $teamTie->entry1_id);

        $outcome = TeamTieOutcomeResolver::resolve($teamTie->fresh());

        $this->assertTrue($outcome['is_decided']);
        $this->assertSame((int) $teamTie->entry1_id, $outcome['winner_entry_id']);
        $this->assertSame(3, $outcome['entry1_wins']);
        $this->assertSame(0, $outcome['entry2_wins']);
        $this->assertSame(3, $outcome['clinch_slot_order']);
        $this->assertSame([4, 5], $outcome['slots_to_mark_not_needed']);
    }

    public function test_post_clinch_finished_rubbers_do_not_count_in_official_score(): void
    {
        $teamTie = $this->createScheduledTeamTie();

        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 2, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 3, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 4, (int) $teamTie->entry2_id);
        $this->finishRubberSlot($teamTie, 5, (int) $teamTie->entry2_id);

        $outcome = TeamTieOutcomeResolver::resolve($teamTie->fresh());

        $this->assertSame(3, $outcome['entry1_wins']);
        $this->assertSame(0, $outcome['entry2_wins']);
        $this->assertSame(3, $outcome['rubbers_counting']);
        $this->assertSame(5, $outcome['rubbers_finished_total']);
        $this->assertSame([], $outcome['slots_to_mark_not_needed']);
    }

    public function test_out_of_order_results_use_slot_order_official_score(): void
    {
        $teamTie = $this->createScheduledTeamTie();

        $this->finishRubberSlot($teamTie, 4, (int) $teamTie->entry2_id);
        $this->finishRubberSlot($teamTie, 5, (int) $teamTie->entry2_id);
        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 2, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 3, (int) $teamTie->entry1_id);

        $outcome = TeamTieOutcomeResolver::resolve($teamTie->fresh());

        $this->assertTrue($outcome['is_decided']);
        $this->assertSame((int) $teamTie->entry1_id, $outcome['winner_entry_id']);
        $this->assertSame(3, $outcome['entry1_wins']);
        $this->assertSame(0, $outcome['entry2_wins']);
        $this->assertSame(5, $outcome['rubbers_finished_total']);
        $this->assertSame(3, $outcome['rubbers_counting']);
    }

    public function test_not_needed_slots_are_ignored_and_reopen_when_tie_open(): void
    {
        $teamTie = $this->createScheduledTeamTie();
        $slotFive = $this->rubberAt($teamTie, 5);

        $slotFive->game->update(['status' => GameStatus::NotNeeded]);

        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 2, (int) $teamTie->entry2_id);

        $outcome = TeamTieOutcomeResolver::resolve($teamTie->fresh());

        $this->assertFalse($outcome['is_decided']);
        $this->assertSame([5], $outcome['slots_to_reopen']);
    }

    public function test_entry2_winner_is_detected(): void
    {
        $teamTie = $this->createScheduledTeamTie();

        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry2_id);
        $this->finishRubberSlot($teamTie, 2, (int) $teamTie->entry2_id);
        $this->finishRubberSlot($teamTie, 3, (int) $teamTie->entry2_id);

        $outcome = TeamTieOutcomeResolver::resolve($teamTie->fresh());

        $this->assertSame((int) $teamTie->entry2_id, $outcome['winner_entry_id']);
        $this->assertSame(3, $outcome['entry2_wins']);
    }

    public function test_official_rubbers_returns_only_three_for_three_zero_with_five_finished(): void
    {
        $teamTie = $this->createScheduledTeamTie();

        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 2, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 3, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 4, (int) $teamTie->entry2_id);
        $this->finishRubberSlot($teamTie, 5, (int) $teamTie->entry2_id);

        $officialRubbers = TeamTieOutcomeResolver::officialRubbers($teamTie->fresh());

        $this->assertCount(3, $officialRubbers);
        $this->assertSame([1, 2, 3], array_column($officialRubbers, 'slot_order'));
    }

    public function test_official_rubbers_ignores_not_needed_slots(): void
    {
        $teamTie = $this->createScheduledTeamTie();
        $this->rubberAt($teamTie, 5)->game->update(['status' => GameStatus::NotNeeded]);

        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 2, (int) $teamTie->entry2_id);

        $officialRubbers = TeamTieOutcomeResolver::officialRubbers($teamTie->fresh());

        $this->assertCount(2, $officialRubbers);
        $this->assertSame([1, 2], array_column($officialRubbers, 'slot_order'));
    }

    public function test_official_rubbers_respects_slot_order_with_out_of_order_results(): void
    {
        $teamTie = $this->createScheduledTeamTie();

        $this->finishRubberSlot($teamTie, 4, (int) $teamTie->entry2_id);
        $this->finishRubberSlot($teamTie, 5, (int) $teamTie->entry2_id);
        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 2, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 3, (int) $teamTie->entry1_id);

        $officialRubbers = TeamTieOutcomeResolver::officialRubbers($teamTie->fresh());

        $this->assertCount(3, $officialRubbers);
        $this->assertSame([1, 2, 3], array_column($officialRubbers, 'slot_order'));
    }

    public function test_official_rubbers_returns_four_for_three_one_score(): void
    {
        $teamTie = $this->createScheduledTeamTie();

        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 2, (int) $teamTie->entry2_id);
        $this->finishRubberSlot($teamTie, 3, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 4, (int) $teamTie->entry1_id);

        $officialRubbers = TeamTieOutcomeResolver::officialRubbers($teamTie->fresh());

        $this->assertCount(4, $officialRubbers);
        $this->assertSame([1, 2, 3, 4], array_column($officialRubbers, 'slot_order'));
    }

    public function test_official_rubbers_returns_five_for_three_two_score(): void
    {
        $teamTie = $this->createScheduledTeamTie();

        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 2, (int) $teamTie->entry2_id);
        $this->finishRubberSlot($teamTie, 3, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 4, (int) $teamTie->entry2_id);
        $this->finishRubberSlot($teamTie, 5, (int) $teamTie->entry1_id);

        $officialRubbers = TeamTieOutcomeResolver::officialRubbers($teamTie->fresh());

        $this->assertCount(5, $officialRubbers);
        $this->assertSame([1, 2, 3, 4, 5], array_column($officialRubbers, 'slot_order'));
    }

    public function test_official_rubbers_expose_game_and_entry_fields(): void
    {
        $teamTie = $this->createScheduledTeamTie();
        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);

        $officialRubbers = TeamTieOutcomeResolver::officialRubbers($teamTie->fresh());

        $this->assertArrayHasKey('game', $officialRubbers[0]);
        $this->assertArrayHasKey('team_tie_game', $officialRubbers[0]);
        $this->assertSame((int) $teamTie->entry1_id, $officialRubbers[0]['winner_entry_id']);
        $this->assertSame((int) $teamTie->entry1_id, $officialRubbers[0]['entry1_id']);
        $this->assertSame((int) $teamTie->entry2_id, $officialRubbers[0]['entry2_id']);
    }

    private function createScheduledTeamTie(): TeamTie
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();

        return TeamTie::query()
            ->where('group_id', $group->id)
            ->with('teamTieGames.game')
            ->firstOrFail();
    }

    private function rubberAt(TeamTie $teamTie, int $slotOrder): TeamTieGame
    {
        return $teamTie->teamTieGames()->where('slot_order', $slotOrder)->firstOrFail();
    }

    private function finishRubberSlot(TeamTie $teamTie, int $slotOrder, int $winnerEntryId): void
    {
        $teamTieGame = $this->rubberAt($teamTie, $slotOrder);

        $teamTieGame->game->update([
            'status' => GameStatus::Finished,
            'winner_entry_id' => $winnerEntryId,
            'finished_at' => now(),
        ]);
    }
}
