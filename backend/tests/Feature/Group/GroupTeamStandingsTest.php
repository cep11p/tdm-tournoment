<?php

namespace Tests\Feature\Group;

use App\Enums\AuditAction;
use App\Enums\CompetitionType;
use App\Enums\TeamTieModality;
use App\Enums\TeamTieStatus;
use App\Models\CompetitionEntryMember;
use App\Models\GroupManualTiebreak;
use App\Models\GroupManualTiebreakEntry;
use App\Models\TeamTie;
use App\Models\TeamTieGame;
use App\Support\Bracket\GroupBracketReadiness;
use App\Support\Bracket\GroupQualifiersCollector;
use App\Support\Group\GroupStandingsResolver;
use App\Support\Group\TeamGroupStandingsCalculator;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class GroupTeamStandingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_four_team_group_standings_basic_stats(): void
    {
        $setup = $this->createFourTeamGroup();
        $context = $setup['context'];
        $group = $setup['group'];
        $entries = $setup['entries'];

        $this->finishAllTeamTiesWithDominantWinner($context, $group, $entries[0]);

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('meta.standings_are_provisional', false)
            ->assertJsonPath('meta.finished_team_ties_count', 6)
            ->assertJsonPath('meta.total_team_ties_count', 6)
            ->assertJsonPath('data.0.competition_entry_id', $entries[0]->id)
            ->assertJsonPath('data.0.won', 3)
            ->assertJsonPath('data.0.lost', 0)
            ->assertJsonPath('data.0.played', 3)
            ->assertJsonPath('data.0.player_id', null)
            ->assertJsonPath('data.0.player_name', null);

        $this->assertNotNull($response->json('data.0.display_name'));
        $this->assertCount(4, $response->json('data.0.members'));
    }

    public function test_post_clinch_extra_rubbers_do_not_affect_standings_metrics(): void
    {
        $setup = $this->createFourTeamGroup();
        $context = $setup['context'];
        $group = $setup['group'];
        $entries = $setup['entries'];

        $teamTie = TeamTie::query()
            ->where('group_id', $group->id)
            ->where('entry1_id', $entries[0]->id)
            ->where('entry2_id', $entries[1]->id)
            ->firstOrFail();

        $this->winRubber($context, $teamTie, $entries, 1, (int) $entries[0]->id);
        $this->winRubber($context, $teamTie, $entries, 2, (int) $entries[0]->id);
        $this->winRubber($context, $teamTie, $entries, 3, (int) $entries[0]->id);

        $this->finishRubberSlotDirectly($teamTie, 4, (int) $entries[1]->id);
        $this->finishRubberSlotDirectly($teamTie, 5, (int) $entries[1]->id);

        $result = app(TeamGroupStandingsCalculator::class)->calculate($group->fresh());
        $standing = $result->standings->firstWhere('competitionEntryId', $entries[0]->id);

        $this->assertNotNull($standing);
        $this->assertSame(1, $standing->won);
        $this->assertSame(3, $standing->rubbersWon);
        $this->assertSame(0, $standing->rubbersLost);
        $this->assertSame(3, $standing->rubberDifference);
    }

    public function test_provisional_standings_while_team_tie_pending(): void
    {
        $setup = $this->createFourTeamGroup();
        $context = $setup['context'];
        $group = $setup['group'];
        $entries = $setup['entries'];

        $firstTie = TeamTie::query()->where('group_id', $group->id)->firstOrFail();
        $this->winTeamTie($context, $firstTie, $entries, (int) $entries[0]->id);

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('meta.standings_are_provisional', true)
            ->assertJsonPath('meta.requires_manual_tiebreak', false)
            ->assertJsonPath('meta.finished_team_ties_count', 1)
            ->assertJsonPath('meta.total_team_ties_count', 6);
    }

    public function test_reopened_team_tie_removes_win_and_makes_standings_provisional(): void
    {
        $setup = $this->createTwoTeamGroup();
        $context = $setup['context'];
        $group = $setup['group'];
        $entries = $setup['entries'];

        $teamTie = TeamTie::query()->where('group_id', $group->id)->firstOrFail();
        $this->winTeamTieThreeOne($context, $teamTie, $entries, (int) $entries[0]->id);

        $slotFour = $this->rubberAt($teamTie->fresh(), 4);
        $context->correctResult($slotFour->game->fresh(), 'Corrección', [
            ['player1_score' => 5, 'player2_score' => 11],
        ])->assertOk();

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('meta.standings_are_provisional', true);

        $standing = collect($response->json('data'))
            ->firstWhere('competition_entry_id', $entries[0]->id);

        $this->assertSame(0, $standing['won']);
    }

    public function test_two_way_tie_resolves_with_head_to_head(): void
    {
        $setup = $this->createFourTeamGroup();
        $context = $setup['context'];
        $group = $setup['group'];
        [$entryA, $entryB, $entryC, $entryD] = $setup['entries'];

        $this->finishTeamTieBetween($context, $group, $setup['entries'], $entryA, $entryB, (int) $entryA->id);
        $this->finishTeamTieBetween($context, $group, $setup['entries'], $entryA, $entryC, (int) $entryA->id);
        $this->finishTeamTieBetween($context, $group, $setup['entries'], $entryA, $entryD, (int) $entryD->id);
        $this->finishTeamTieBetween($context, $group, $setup['entries'], $entryB, $entryC, (int) $entryB->id);
        $this->finishTeamTieBetween($context, $group, $setup['entries'], $entryB, $entryD, (int) $entryB->id);
        $this->finishTeamTieBetween($context, $group, $setup['entries'], $entryC, $entryD, (int) $entryC->id);

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('data.0.competition_entry_id', $entryA->id)
            ->assertJsonPath('data.1.competition_entry_id', $entryB->id)
            ->assertJsonPath('data.0.won', 2)
            ->assertJsonPath('data.1.won', 2)
            ->assertJsonPath('meta.requires_manual_tiebreak', false);
    }

    public function test_unresolved_three_way_tie_requires_manual_tiebreak(): void
    {
        $setup = $this->createThreeTeamBalancedGroup();
        $context = $setup['context'];
        $group = $setup['group'];

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('meta.standings_are_provisional', false)
            ->assertJsonPath('meta.requires_manual_tiebreak', true)
            ->assertJsonCount(1, 'meta.manual_tiebreak_groups')
            ->assertJsonCount(3, 'data');
    }

    public function test_manual_tiebreak_resolves_team_standings(): void
    {
        $setup = $this->createThreeTeamBalancedGroup();
        $context = $setup['context'];
        $group = $setup['group'];
        $entries = $setup['entries'];

        $entryIds = array_map(fn ($entry) => $entry->id, $entries);

        $this->postJson($context->apiUrl("groups/{$group->id}/manual-tiebreaks"), [
            'entry_ids' => [$entryIds[2], $entryIds[0], $entryIds[1]],
            'reason' => 'draw',
        ])->assertCreated();

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('meta.requires_manual_tiebreak', false)
            ->assertJsonPath('data.0.competition_entry_id', $entryIds[2])
            ->assertJsonPath('data.0.manual_tiebreak_applied', true);
    }

    public function test_stale_manual_tiebreak_after_team_tie_correction(): void
    {
        $setup = $this->createThreeTeamBalancedGroup();
        $context = $setup['context'];
        $group = $setup['group'];
        $entries = $setup['entries'];

        $entryIds = array_map(fn ($entry) => $entry->id, $entries);

        $this->postJson($context->apiUrl("groups/{$group->id}/manual-tiebreaks"), [
            'entry_ids' => [$entryIds[0], $entryIds[1], $entryIds[2]],
            'reason' => 'draw',
        ])->assertCreated();

        $teamTie = TeamTie::query()
            ->where('group_id', $group->id)
            ->where(function ($query) use ($entries): void {
                $query->where(function ($inner) use ($entries): void {
                    $inner->where('entry1_id', $entries[0]->id)
                        ->where('entry2_id', $entries[2]->id);
                })->orWhere(function ($inner) use ($entries): void {
                    $inner->where('entry1_id', $entries[2]->id)
                        ->where('entry2_id', $entries[0]->id);
                });
            })
            ->firstOrFail();

        $slotFive = $this->rubberAt($teamTie->fresh(), 5);
        $context->correctResult($slotFive->game->fresh(), 'Corrección', [
            ['player1_score' => 5, 'player2_score' => 11],
        ])->assertOk();

        $this->getJson($context->apiUrl("groups/{$group->id}/standings"))
            ->assertOk()
            ->assertJsonCount(1, 'meta.stale_manual_tiebreaks');
    }

    public function test_qualifiers_return_team_entries(): void
    {
        $setup = $this->createTwoTeamGroup();
        $context = $setup['context'];
        $group = $setup['group'];
        $entries = $setup['entries'];
        $competition = $setup['competition'];
        $competition->update(['qualified_per_group' => 1]);

        $teamTie = TeamTie::query()->where('group_id', $group->id)->firstOrFail();
        $this->winTeamTie($context, $teamTie, $entries, (int) $entries[0]->id);

        $qualifiers = app(GroupQualifiersCollector::class)->collect($competition->fresh());

        $this->assertCount(1, $qualifiers);
        $this->assertSame($entries[0]->id, $qualifiers[0]->competitionEntryId);
        $this->assertNull($qualifiers[0]->playerId);
        $this->assertNull($qualifiers[0]->playerName);
        $this->assertNotEmpty($qualifiers[0]->members);
        $this->assertNotEmpty($qualifiers[0]->displayName);
    }

    public function test_cutoff_tie_blocks_bracket_readiness(): void
    {
        $setup = $this->createThreeTeamBalancedGroup();
        $group = $setup['group'];

        $this->assertTrue(
            app(GroupBracketReadiness::class)->groupRequiresAttentionBeforeBracket($group->fresh(), 1)
        );
    }

    public function test_all_finished_resolved_team_group_is_ready_for_bracket(): void
    {
        $setup = $this->createTwoTeamGroup();
        $context = $setup['context'];
        $group = $setup['group'];
        $entries = $setup['entries'];
        $competition = $setup['competition'];

        $teamTie = TeamTie::query()->where('group_id', $group->id)->firstOrFail();
        $this->winTeamTie($context, $teamTie, $entries, (int) $entries[0]->id);

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'ready_for_bracket');
    }

    public function test_reopened_team_tie_moves_competition_back_to_group_stage_in_progress(): void
    {
        $setup = $this->createTwoTeamGroup();
        $context = $setup['context'];
        $group = $setup['group'];
        $entries = $setup['entries'];
        $competition = $setup['competition'];

        $teamTie = TeamTie::query()->where('group_id', $group->id)->firstOrFail();
        $this->winTeamTieThreeOne($context, $teamTie, $entries, (int) $entries[0]->id);

        $slotFour = $this->rubberAt($teamTie->fresh(), 4);
        $context->correctResult($slotFour->game->fresh(), 'Corrección', [
            ['player1_score' => 5, 'player2_score' => 11],
        ])->assertOk();

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'group_stage_in_progress');
    }

    public function test_global_team_standings_endpoint_is_rejected(): void
    {
        $setup = $this->createFourTeamGroup();
        $context = $setup['context'];
        $competition = $setup['competition'];

        $this->getJson($context->apiUrl("competitions/{$competition->id}/standings"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);
    }

    public function test_resolver_uses_team_calculator_for_team_groups(): void
    {
        $setup = $this->createFourTeamGroup();
        $group = $setup['group'];

        $this->assertTrue(app(GroupStandingsResolver::class)->isGroupComplete($group->fresh()) === false);
    }

    /**
     * @return array{
     *     context: \Tests\Support\TournamentTestContext,
     *     competition: \App\Models\Competition,
     *     group: \App\Models\Group,
     *     entries: list<\App\Models\CompetitionEntry>
     * }
     */
    private function createFourTeamGroup(): array
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 4, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();

        return compact('context', 'competition', 'group', 'entries');
    }

    /**
     * @return array{
     *     context: \Tests\Support\TournamentTestContext,
     *     competition: \App\Models\Competition,
     *     group: \App\Models\Group,
     *     entries: list<\App\Models\CompetitionEntry>
     * }
     */
    private function createTwoTeamGroup(): array
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();

        return compact('context', 'competition', 'group', 'entries');
    }

    /**
     * @return array{
     *     context: \Tests\Support\TournamentTestContext,
     *     competition: \App\Models\Competition,
     *     group: \App\Models\Group,
     *     entries: list<\App\Models\CompetitionEntry>
     * }
     */
    private function createThreeTeamBalancedGroup(): array
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, setsToWin: 1);
        $entries = $context->registerTeams($competition, 3, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();

        [$entryA, $entryB, $entryC] = $entries;

        $this->finishBalancedTeamTie($context, $group, $entries, $entryA, $entryB);
        $this->finishBalancedTeamTie($context, $group, $entries, $entryB, $entryC);
        $this->finishBalancedTeamTie($context, $group, $entries, $entryC, $entryA);

        return compact('context', 'competition', 'group', 'entries');
    }

    /**
     * @param  list<\App\Models\CompetitionEntry>  $entries
     */
    private function finishAllTeamTiesWithDominantWinner(
        \Tests\Support\TournamentTestContext $context,
        \App\Models\Group $group,
        \App\Models\CompetitionEntry $dominantEntry,
    ): void {
        $entries = $this->entriesForGroup($group);
        $teamTies = TeamTie::query()->where('group_id', $group->id)->get();

        foreach ($teamTies as $teamTie) {
            $winnerId = (int) $dominantEntry->id === (int) $teamTie->entry1_id
                || (int) $dominantEntry->id === (int) $teamTie->entry2_id
                ? (int) $dominantEntry->id
                : (int) $teamTie->entry1_id;

            $this->winTeamTie($context, $teamTie, $entries, $winnerId);
        }
    }

    /**
     * @param  list<\App\Models\CompetitionEntry>  $entries
     */
    private function finishTeamTieBetween(
        \Tests\Support\TournamentTestContext $context,
        \App\Models\Group $group,
        array $entries,
        \App\Models\CompetitionEntry $left,
        \App\Models\CompetitionEntry $right,
        int $winnerEntryId,
    ): void {
        $teamTie = TeamTie::query()
            ->where('group_id', $group->id)
            ->where(function ($query) use ($left, $right): void {
                $query->where(function ($inner) use ($left, $right): void {
                    $inner->where('entry1_id', $left->id)->where('entry2_id', $right->id);
                })->orWhere(function ($inner) use ($left, $right): void {
                    $inner->where('entry1_id', $right->id)->where('entry2_id', $left->id);
                });
            })
            ->firstOrFail();

        $this->winTeamTie($context, $teamTie, $entries, $winnerEntryId);
    }

    /**
     * @param  list<\App\Models\CompetitionEntry>  $entries
     */
    private function finishBalancedTeamTie(
        \Tests\Support\TournamentTestContext $context,
        \App\Models\Group $group,
        array $entries,
        \App\Models\CompetitionEntry $left,
        \App\Models\CompetitionEntry $right,
    ): void {
        $teamTie = TeamTie::query()
            ->where('group_id', $group->id)
            ->where(function ($query) use ($left, $right): void {
                $query->where(function ($inner) use ($left, $right): void {
                    $inner->where('entry1_id', $left->id)->where('entry2_id', $right->id);
                })->orWhere(function ($inner) use ($left, $right): void {
                    $inner->where('entry1_id', $right->id)->where('entry2_id', $left->id);
                });
            })
            ->firstOrFail();

        $leftId = (int) $left->id;
        $rightId = (int) $right->id;

        $this->winRubberWithSets($context, $teamTie, $entries, 1, $leftId, [[11, 9]]);
        $this->winRubberWithSets($context, $teamTie, $entries, 2, $rightId, [[11, 9]]);
        $this->winRubberWithSets($context, $teamTie, $entries, 3, $leftId, [[11, 9]]);
        $this->winRubberWithSets($context, $teamTie, $entries, 4, $rightId, [[11, 9]]);
        $this->winRubberWithSets($context, $teamTie, $entries, 5, $leftId, [[11, 9]]);
    }

    /**
     * @return list<\App\Models\CompetitionEntry>
     */
    private function entriesForGroup(\App\Models\Group $group): array
    {
        return $group->groupEntries()
            ->with('competitionEntry')
            ->get()
            ->map(fn ($groupEntry) => $groupEntry->competitionEntry)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<\App\Models\CompetitionEntry>  $entries
     */
    private function winTeamTieThreeOne(
        \Tests\Support\TournamentTestContext $context,
        TeamTie $teamTie,
        array $entries,
        int $winnerEntryId,
    ): void {
        $loserEntryId = (int) $teamTie->entry1_id === $winnerEntryId
            ? (int) $teamTie->entry2_id
            : (int) $teamTie->entry1_id;

        $this->winRubber($context, $teamTie, $entries, 1, $winnerEntryId);
        $this->winRubber($context, $teamTie, $entries, 2, $loserEntryId);
        $this->winRubber($context, $teamTie, $entries, 3, $winnerEntryId);
        $this->winRubber($context, $teamTie, $entries, 4, $winnerEntryId);
    }

    private function finishRubberSlotDirectly(TeamTie $teamTie, int $slotOrder, int $winnerEntryId): void
    {
        $teamTieGame = $this->rubberAt($teamTie, $slotOrder);

        $teamTieGame->game->update([
            'status' => \App\Enums\GameStatus::Finished,
            'winner_entry_id' => $winnerEntryId,
            'finished_at' => now(),
        ]);
    }

    /**
     * @param  list<\App\Models\CompetitionEntry>  $entries
     */
    private function winTeamTie(
        \Tests\Support\TournamentTestContext $context,
        TeamTie $teamTie,
        array $entries,
        int $winnerEntryId,
    ): void {
        foreach ([1, 2, 3] as $slot) {
            $this->winRubber($context, $teamTie->fresh(), $entries, $slot, $winnerEntryId);
        }
    }

    /**
     * @param  list<\App\Models\CompetitionEntry>  $entries
     * @param  list<array{int, int}>  $sets
     */
    private function winRubberWithSets(
        \Tests\Support\TournamentTestContext $context,
        TeamTie $teamTie,
        array $entries,
        int $slotOrder,
        int $winnerEntryId,
        array $sets,
    ): void {
        $rubber = $this->rubberAt($teamTie, $slotOrder);
        $this->lineupRubber($context, $rubber, $entries);

        foreach ($sets as $index => [$leftScore, $rightScore]) {
            $player1IsWinner = (int) $rubber->game->entry1_id === $winnerEntryId;
            $player1Score = $player1IsWinner ? $leftScore : $rightScore;
            $player2Score = $player1IsWinner ? $rightScore : $leftScore;

            $context->recordSet(
                $rubber->game->fresh(),
                $index + 1,
                $player1Score,
                $player2Score,
            )->assertOk();
        }
    }

    /**
     * @param  list<\App\Models\CompetitionEntry>  $entries
     */
    private function winRubber(
        \Tests\Support\TournamentTestContext $context,
        TeamTie $teamTie,
        array $entries,
        int $slotOrder,
        int $winnerEntryId,
    ): void {
        $rubber = $this->rubberAt($teamTie, $slotOrder);
        $this->lineupRubber($context, $rubber, $entries);
        $context->finishGameByEntryViaApi($rubber->game->fresh(), $winnerEntryId)->assertOk();
    }

    private function rubberAt(TeamTie $teamTie, int $slotOrder): TeamTieGame
    {
        return $teamTie->teamTieGames()->where('slot_order', $slotOrder)->firstOrFail();
    }

    /**
     * @param  list<\App\Models\CompetitionEntry>  $entries
     */
    private function lineupRubber(
        \Tests\Support\TournamentTestContext $context,
        TeamTieGame $rubber,
        array $entries,
    ): void {
        $teamTie = $rubber->teamTie()->firstOrFail();
        $entry1 = collect($entries)->firstWhere('id', $teamTie->entry1_id);
        $entry2 = collect($entries)->firstWhere('id', $teamTie->entry2_id);
        $requiredPerSide = $rubber->modality === TeamTieModality::Doubles ? 2 : 1;

        $context->setTeamTieGameLineup($rubber, [
            'entry1_player_ids' => $this->playerIds($entry1, $requiredPerSide),
            'entry2_player_ids' => $this->playerIds($entry2, $requiredPerSide),
        ])->assertOk();
    }

    /**
     * @return list<int>
     */
    private function playerIds(\App\Models\CompetitionEntry $entry, int $count): array
    {
        return CompetitionEntryMember::query()
            ->where('competition_entry_id', $entry->id)
            ->orderBy('member_order')
            ->limit($count)
            ->pluck('player_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
