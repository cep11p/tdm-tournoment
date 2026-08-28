<?php

namespace Tests\Feature\Group;

use App\Actions\Group\BuildGroupRoundRobinGamesAction;
use App\Enums\GameStatus;
use App\Enums\GroupPlayerStatus;
use App\Models\GameSet;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupEntry;
use App\Support\Bracket\GroupBracketReadiness;
use App\Support\Bracket\GroupQualifiersCollector;
use App\Support\Group\GroupStandingsCalculator;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class GroupDoublesStandingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_generates_two_groups_with_three_pairs_each(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createDoublesCompetitionWithSixPairs($context);

        $response = $context->generateRandomGroups($setup['competition'], groupsCount: 2);

        $response->assertCreated();
        $this->assertSame(2, $response->json('groups_created'));
        $this->assertSame(6, $response->json('players_assigned'));
        $this->assertCount(2, $response->json('groups'));

        $groups = Group::query()->where('competition_id', $setup['competition']->id)->get();
        $this->assertCount(2, $groups);
        $this->assertSame(3, $groups[0]->groupEntries()->count());
        $this->assertSame(3, $groups[1]->groupEntries()->count());
    }

    public function test_group_resource_returns_one_row_per_pair(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createDoublesCompetitionWithSixPairs($context);
        $context->generateRandomGroups($setup['competition'], groupsCount: 2)->assertCreated();

        $group = Group::query()->where('competition_id', $setup['competition']->id)->firstOrFail();

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/groups"));

        $response->assertOk();

        $groupPayload = collect($response->json('data'))
            ->firstWhere('id', $group->id);

        $this->assertCount(3, $groupPayload['group_players']);
    }

    public function test_group_player_resource_shape_for_doubles(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createDoublesCompetitionWithSixPairs($context);
        $context->generateRandomGroups($setup['competition'], groupsCount: 2)->assertCreated();

        $group = Group::query()->where('competition_id', $setup['competition']->id)->firstOrFail();
        $entryId = (int) $group->groupEntries()->value('competition_entry_id');
        $entry = CompetitionEntry::query()->with('members.player')->findOrFail($entryId);

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/players"));

        $response->assertOk();

        $groupPlayer = collect($response->json('data'))
            ->firstWhere('competition_entry_id', $entry->id);

        $this->assertNotNull($groupPlayer);
        $this->assertSame($entry->id, $groupPlayer['competition_entry_id']);
        $memberNames = $entry->members->sortBy('member_order')->map(
            fn ($member) => trim(sprintf('%s %s', $member->player->first_name, $member->player->last_name))
        )->values()->all();
        $this->assertSame(implode(' / ', $memberNames), $groupPlayer['display_name']);
        $this->assertCount(2, $groupPlayer['members']);
        $this->assertNull($groupPlayer['player_id']);
        $this->assertNull($groupPlayer['player_name']);
        $this->assertNull($groupPlayer['player']);
    }

    public function test_round_robin_games_use_entry_ids(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createDoublesCompetitionWithSixPairs($context);
        $context->generateRandomGroups($setup['competition'], groupsCount: 2)->assertCreated();

        $group = Group::query()->where('competition_id', $setup['competition']->id)->firstOrFail();
        $entryIds = $group->groupEntries()->pluck('competition_entry_id')->all();

        $games = Game::query()->where('group_id', $group->id)->get();

        $this->assertGreaterThan(0, $games->count());

        foreach ($games as $game) {
            $this->assertContains((int) $game->entry1_id, $entryIds);
            $this->assertContains((int) $game->entry2_id, $entryIds);
        }
    }

    public function test_standings_calculate_per_pair(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedDoublesGroup($context);
        $group = $setup['group'];
        $winnerEntry = $setup['entries'][0];

        $result = app(GroupStandingsCalculator::class)->calculate($group->fresh());
        $first = $result->standings->first();

        $this->assertSame($winnerEntry->id, $first->competitionEntryId);
        $this->assertNull($first->playerId);
        $this->assertNull($first->playerName);
        $this->assertSame(2, $first->won);
    }

    public function test_standings_json_exposes_entry_fields_for_doubles(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedDoublesGroup($context);
        $group = $setup['group'];

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response->assertOk();

        $standing = $response->json('data.0');
        $this->assertNotNull($standing['competition_entry_id']);
        $this->assertNotNull($standing['display_name']);
        $this->assertCount(2, $standing['members']);
        $this->assertNull($standing['player_id']);
        $this->assertNull($standing['player_name']);
    }

    public function test_manual_tiebreak_accepts_entry_ids_for_doubles(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedDoublesTripleTie($context);
        $group = $setup['group'];
        [$entryA, $entryB, $entryC] = $setup['entries'];

        $this->postJson($context->apiUrl("groups/{$group->id}/manual-tiebreaks"), [
            'entry_ids' => [$entryB->id, $entryA->id, $entryC->id],
            'reason' => 'draw',
        ])->assertCreated();

        $standings = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));
        $standings->assertOk();
        $standings->assertJsonPath('data.0.competition_entry_id', $entryB->id);
        $standings->assertJsonPath('meta.requires_manual_tiebreak', false);
    }

    public function test_readiness_detects_pending_tie_in_doubles(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedDoublesTripleTie($context);
        $group = $setup['group'];
        $setup['competition']->update(['qualified_per_group' => 2]);

        $this->assertTrue(
            app(GroupBracketReadiness::class)->groupRequiresAttentionBeforeBracket($group->fresh(), 2)
        );
    }

    public function test_qualifiers_return_competition_entry_id_for_doubles(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedDoublesGroup($context);
        $competition = $setup['competition'];
        $competition->update(['qualified_per_group' => 2]);

        $qualifiers = app(GroupQualifiersCollector::class)->collect($competition->fresh());

        $this->assertGreaterThanOrEqual(2, $qualifiers->count());
        $this->assertNull($qualifiers[0]->playerId);
        $this->assertNull($qualifiers[0]->playerName);
        $this->assertNotEmpty($qualifiers[0]->displayName);
        $this->assertCount(2, $qualifiers[0]->members);
        $this->assertSame($setup['entries'][0]->id, $qualifiers[0]->competitionEntryId);
    }

    public function test_status_change_by_competition_entry_id(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createDoublesCompetitionWithSixPairs($context);
        $context->generateRandomGroups($setup['competition'], groupsCount: 2)->assertCreated();

        $entry = $setup['entries'][0];
        $groupEntry = GroupEntry::query()
            ->where('competition_id', $setup['competition']->id)
            ->where('competition_entry_id', $entry->id)
            ->firstOrFail();
        $group = Group::query()->findOrFail($groupEntry->group_id);

        $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'competition_entry_id' => $entry->id,
            'status' => GroupPlayerStatus::Withdrawn->value,
            'reason' => 'injury',
        ])->assertCreated()
            ->assertJsonPath('data.competition_entry_id', $entry->id)
            ->assertJsonPath('data.status', GroupPlayerStatus::Withdrawn->value);

        $this->assertDatabaseHas('group_entries', [
            'group_id' => $group->id,
            'competition_entry_id' => $entry->id,
            'status' => GroupPlayerStatus::Withdrawn->value,
        ]);
    }

    public function test_manual_assignment_by_competition_entry_id(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(4);
        $entries = $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
        ]);
        $group = $context->createGroup($competition);

        $context->assignEntryToGroupViaApi($group, $entries[0])->assertCreated();
        $context->assignEntryToGroupViaApi($group, $entries[1])->assertCreated();

        $this->assertDatabaseCount('group_entries', 2);
        $this->assertDatabaseHas('group_entries', [
            'group_id' => $group->id,
            'competition_entry_id' => $entries[0]->id,
        ]);
    }

    public function test_player_id_assignment_rejects_for_doubles(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(2);
        $context->registerPair($competition, $players[0], $players[1]);
        $group = $context->createGroup($competition);

        $context->assignPlayerToGroupViaApi($group, $players[0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id', 'competition_entry_id']);
    }

    /**
     * @return array{
     *     competition: \App\Models\Competition,
     *     entries: array<int, CompetitionEntry>,
     *     players: array<int, \App\Models\Player>
     * }
     */
    private function createDoublesCompetitionWithSixPairs(TournamentTestContext $context): array
    {
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(12);
        $pairs = [];

        for ($index = 0; $index < 12; $index += 2) {
            $pairs[] = [$players[$index], $players[$index + 1]];
        }

        $entries = $context->registerPairs($competition, $pairs);

        return [
            'competition' => $competition,
            'entries' => $entries,
            'players' => $players,
        ];
    }

    /**
     * @return array{
     *     competition: \App\Models\Competition,
     *     group: Group,
     *     entries: array<int, CompetitionEntry>
     * }
     */
    private function createFinishedDoublesGroup(TournamentTestContext $context): array
    {
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(6);
        $entries = $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
        ]);
        $group = $context->createGroup($competition);

        foreach ($entries as $entry) {
            $context->assignEntryToGroupViaApi($group, $entry)->assertCreated();
        }

        app(BuildGroupRoundRobinGamesAction::class)($group->fresh());
        $games = Game::query()->where('group_id', $group->id)->get();

        $context->finishGameByEntry($context->findGameBetweenEntries($games, $entries[0]->id, $entries[1]->id), $entries[0]->id);
        $context->finishGameByEntry($context->findGameBetweenEntries($games, $entries[0]->id, $entries[2]->id), $entries[0]->id);
        $context->finishGameByEntry($context->findGameBetweenEntries($games, $entries[1]->id, $entries[2]->id), $entries[1]->id);

        return [
            'competition' => $competition,
            'group' => $group,
            'entries' => $entries,
        ];
    }

    /**
     * @return array{
     *     competition: \App\Models\Competition,
     *     group: Group,
     *     entries: array<int, CompetitionEntry>
     * }
     */
    private function createUnresolvedDoublesTripleTie(TournamentTestContext $context): array
    {
        $competition = $context->createDoublesCompetition(setsToWin: 3);
        $players = $context->createPlayers(6);
        $entries = $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
        ]);
        $group = $context->createGroup($competition);

        foreach ($entries as $entry) {
            $context->assignEntryToGroupViaApi($group, $entry)->assertCreated();
        }

        app(BuildGroupRoundRobinGamesAction::class)($group->fresh());
        $games = Game::query()->where('group_id', $group->id)->get();
        $balancedSets = [
            [11, 9],
            [11, 9],
            [9, 11],
            [11, 9],
        ];

        $this->playEntryMatch($context->findGameBetweenEntries($games, $entries[0]->id, $entries[1]->id), $entries[0]->id, $balancedSets);
        $this->playEntryMatch($context->findGameBetweenEntries($games, $entries[1]->id, $entries[2]->id), $entries[1]->id, $balancedSets);
        $this->playEntryMatch($context->findGameBetweenEntries($games, $entries[2]->id, $entries[0]->id), $entries[2]->id, $balancedSets);

        return [
            'competition' => $competition,
            'group' => $group,
            'entries' => $entries,
        ];
    }

    /**
     * @param  array<int, array{int, int}>  $sets
     */
    private function playEntryMatch(
        Game $game,
        int $winnerEntryId,
        array $sets,
    ): void {
        $entry1Wins = (int) $game->entry1_id === $winnerEntryId;

        foreach ($sets as $index => [$leftScore, $rightScore]) {
            GameSet::query()->create([
                'game_id' => $game->id,
                'set_number' => $index + 1,
                'player1_score' => $entry1Wins ? $leftScore : $rightScore,
                'player2_score' => $entry1Wins ? $rightScore : $leftScore,
            ]);
        }

        $game->update([
            'status' => GameStatus::Finished,
            'winner_entry_id' => $winnerEntryId,
            'finished_at' => now(),
        ]);
    }
}
