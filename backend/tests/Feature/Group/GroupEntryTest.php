<?php

namespace Tests\Feature\Group;

use App\Actions\Group\PersistGroupEntryAction;
use App\Enums\CompetitionEntryStatus;
use App\Enums\GroupPlayerStatus;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Group;
use App\Models\GroupEntry;
use App\Models\GroupPlayer;
use Illuminate\Support\Facades\DB;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class GroupEntryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_manual_assignment_creates_group_entry(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $context->registerPlayer($competition, $player);
        $group = $context->createGroup($competition);

        $context->assignPlayerToGroupViaApi($group, $player)->assertCreated();

        $this->assertDatabaseCount('group_entries', 1);
    }

    public function test_manual_assignment_creates_consistent_legacy_group_player(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $context->registerPlayer($competition, $player);
        $group = $context->createGroup($competition);

        $context->assignPlayerToGroupViaApi($group, $player)->assertCreated();

        $entry = CompetitionEntry::query()->sole();
        $groupEntry = GroupEntry::query()->sole();
        $groupPlayer = GroupPlayer::query()->sole();

        $this->assertSame($entry->id, $groupEntry->competition_entry_id);
        $this->assertSame($player->id, $groupPlayer->player_id);
        $this->assertSame($group->id, $groupEntry->group_id);
        $this->assertSame($group->id, $groupPlayer->group_id);
    }

    public function test_group_entry_points_to_correct_competition_entry(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $context->registerPlayer($competition, $player);
        $group = $context->createGroup($competition);

        $context->assignPlayerToGroupViaApi($group, $player)->assertCreated();

        $entry = CompetitionEntry::query()->sole();
        $groupEntry = GroupEntry::query()->sole();

        $this->assertSame($competition->id, $groupEntry->competition_id);
        $this->assertSame($entry->id, $groupEntry->competition_entry_id);
        $this->assertSame($entry->competition_id, $groupEntry->competition_id);
    }

    public function test_cannot_assign_entry_from_another_competition(): void
    {
        $context = $this->tournamentContext();
        $competitionA = $context->createCompetition();
        $competitionB = $context->createCompetition();
        [$playerA, $playerB] = $context->createPlayers(2);
        $context->registerPlayer($competitionA, $playerA);
        $context->registerPlayer($competitionB, $playerB);
        $groupA = $context->createGroup($competitionA);

        $response = $context->assignPlayerToGroupViaApi($groupA, $playerB);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);

        $this->assertDatabaseCount('group_entries', 0);
        $this->assertDatabaseCount('group_players', 0);
    }

    public function test_same_entry_cannot_be_in_two_groups_of_same_competition(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $context->registerPlayer($competition, $player);
        $groupA = $context->createGroup($competition, 'Grupo A');
        $groupB = $context->createGroup($competition, 'Grupo B');

        $context->assignPlayerToGroupViaApi($groupA, $player)->assertCreated();

        $response = $context->assignPlayerToGroupViaApi($groupB, $player);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);

        $this->assertDatabaseCount('group_entries', 1);
        $this->assertDatabaseCount('group_players', 1);
    }

    public function test_same_entry_cannot_be_duplicated_in_same_group(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $context->registerPlayer($competition, $player);
        $group = $context->createGroup($competition);

        $context->assignPlayerToGroupViaApi($group, $player)->assertCreated();

        $response = $context->assignPlayerToGroupViaApi($group, $player);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);

        $this->assertDatabaseCount('group_entries', 1);
        $this->assertDatabaseCount('group_players', 1);
    }

    public function test_random_generation_creates_one_group_entry_per_entry(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $this->assertDatabaseCount('competition_entries', 8);
        $this->assertDatabaseCount('group_entries', 8);
    }

    public function test_random_generation_maintains_group_entry_and_group_player_parity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $this->assertSame(
            GroupEntry::query()->count(),
            GroupPlayer::query()->count(),
        );

        $membersByEntryId = CompetitionEntryMember::query()
            ->where('competition_id', $competition->id)
            ->pluck('player_id', 'competition_entry_id');

        foreach (GroupEntry::query()->get() as $groupEntry) {
            $expectedPlayerId = (int) $membersByEntryId[$groupEntry->competition_entry_id];
            $groupPlayer = GroupPlayer::query()
                ->where('group_id', $groupEntry->group_id)
                ->where('player_id', $expectedPlayerId)
                ->first();

            $this->assertNotNull($groupPlayer);
            $this->assertSame($groupEntry->group_id, $groupPlayer->group_id);
        }
    }

    public function test_regeneration_rebuilds_group_entries(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();
        $originalEntryIds = GroupEntry::query()->pluck('competition_entry_id')->sort()->values()->all();

        $context->regenerateRandomGroups($competition, groupsCount: 3)->assertCreated();

        $this->assertDatabaseCount('group_entries', 6);
        $this->assertSame(
            $originalEntryIds,
            GroupEntry::query()->pluck('competition_entry_id')->sort()->values()->all(),
        );
        $this->assertSame(6, GroupPlayer::query()->count());
    }

    public function test_withdrawn_updates_group_entry_and_group_player(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createGroupWithRoundRobin($context, playerCount: 3);
        $group = $setup['group'];
        $player = $setup['players'][0];

        $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $player->id,
            'status' => 'withdrawn',
            'reason' => 'no_show',
            'notes' => 'No se presentó',
        ])->assertCreated();

        $this->assertDatabaseHas('group_entries', [
            'group_id' => $group->id,
            'status' => GroupPlayerStatus::Withdrawn->value,
            'status_reason' => 'no_show',
            'status_notes' => 'No se presentó',
        ]);

        $this->assertDatabaseHas('group_players', [
            'group_id' => $group->id,
            'player_id' => $player->id,
            'status' => GroupPlayerStatus::Withdrawn->value,
            'status_reason' => 'no_show',
            'status_notes' => 'No se presentó',
        ]);
    }

    public function test_disqualified_updates_both_projections(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createGroupWithRoundRobin($context, playerCount: 3);
        $group = $setup['group'];
        $player = $setup['players'][1];

        $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $player->id,
            'status' => 'disqualified',
            'reason' => 'organizer_decision',
            'notes' => 'Conducta antideportiva',
        ])->assertCreated();

        $this->assertDatabaseHas('group_entries', [
            'group_id' => $group->id,
            'status' => GroupPlayerStatus::Disqualified->value,
            'status_reason' => 'organizer_decision',
        ]);

        $this->assertDatabaseHas('group_players', [
            'group_id' => $group->id,
            'player_id' => $player->id,
            'status' => GroupPlayerStatus::Disqualified->value,
            'status_reason' => 'organizer_decision',
        ]);
    }

    public function test_rollback_does_not_leave_partial_projections_on_persist_failure(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $context->registerPlayer($competition, $player);
        $group = $context->createGroup($competition);
        $entry = CompetitionEntry::query()->sole();

        $threw = false;

        try {
            DB::transaction(function () use ($group, $entry): void {
                app(PersistGroupEntryAction::class)($group, $entry);

                throw new \RuntimeException('Simulated failure after group assignment');
            });
        } catch (\RuntimeException) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertDatabaseCount('group_entries', 0);
        $this->assertDatabaseCount('group_players', 0);
    }

    public function test_tournament_test_context_assign_players_to_group_produces_valid_state(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $this->assertDatabaseCount('group_entries', 3);
        $this->assertDatabaseCount('group_players', 3);
        $this->assertSame(3, $group->groupEntries()->count());
        $this->assertSame(3, $group->groupPlayers()->count());
    }

    public function test_assign_without_competition_entry_fails(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $group = $context->createGroup($competition);

        $response = $context->assignPlayerToGroupViaApi($group, $player);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);

        $this->assertDatabaseCount('group_entries', 0);
        $this->assertDatabaseCount('group_players', 0);
    }

    public function test_persist_group_entry_action_is_atomic_when_invoked_directly(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $context->registerPlayer($competition, $player);
        $group = $context->createGroup($competition);
        $entry = CompetitionEntry::query()->sole();

        $result = app(PersistGroupEntryAction::class)($group, $entry);

        $this->assertInstanceOf(GroupEntry::class, $result['group_entry']);
        $this->assertInstanceOf(GroupPlayer::class, $result['group_player']);
        $this->assertSame($entry->id, $result['group_entry']->competition_entry_id);
        $this->assertSame($player->id, $result['group_player']->player_id);
    }

    public function test_inactive_competition_entries_are_excluded_from_random_generation(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);

        CompetitionEntry::query()
            ->where('competition_id', $competition->id)
            ->orderBy('id')
            ->first()
            ?->update(['status' => CompetitionEntryStatus::Withdrawn]);

        $response = $context->generateRandomGroups($competition, groupsCount: 1);

        $response->assertCreated();
        $this->assertDatabaseCount('group_entries', 2);
        $this->assertDatabaseCount('group_players', 2);
    }

    /**
     * @return array{
     *     competition: \App\Models\Competition,
     *     group: Group,
     *     players: array<int, \App\Models\Player>
     * }
     */
    private function createGroupWithRoundRobin(TournamentTestContext $context, int $playerCount): array
    {
        $competition = $context->createCompetition();
        $players = $context->createPlayers($playerCount);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        return [
            'competition' => $competition,
            'group' => $group,
            'players' => $players,
        ];
    }
}
