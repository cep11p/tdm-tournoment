<?php

namespace Tests\Feature\Group;

use App\Enums\CompetitionEntryStatus;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupEntry;
use App\Support\Group\SeededGroupEntriesGuard;
use Tests\TestCase;

class GenerateSeededRandomGroupsTest extends TestCase
{
    public function test_omitted_seeded_entry_ids_still_generates_balanced_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(12);
        $context->registerPlayers($competition, $players);

        $response = $context->generateRandomGroups($competition, groupsCount: 4);

        $response
            ->assertCreated()
            ->assertJsonPath('groups_created', 4)
            ->assertJsonPath('players_assigned', 12);

        $this->assertSame([3, 3, 3, 3], $this->groupSizes($competition->id));
    }

    public function test_empty_seeded_entry_ids_still_generates_balanced_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(12);
        $context->registerPlayers($competition, $players);

        $response = $context->generateRandomGroups(
            $competition,
            groupsCount: 4,
            seededEntryIds: [],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('groups_created', 4)
            ->assertJsonPath('players_assigned', 12);

        $this->assertSame([3, 3, 3, 3], $this->groupSizes($competition->id));
    }

    public function test_null_seeded_entry_ids_still_generates_balanced_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);

        $response = $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/groups/random-generate"),
            ['groups_count' => 2, 'seeded_entry_ids' => null],
            $this->authHeaders(['organizer']),
        );

        $response
            ->assertCreated()
            ->assertJsonPath('groups_created', 2)
            ->assertJsonPath('players_assigned', 8);
    }

    public function test_places_a_single_seed_in_group_a(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);
        $seededId = (int) $competition->entries()->orderBy('id')->value('id');

        $context->generateRandomGroups(
            $competition,
            groupsCount: 2,
            seededEntryIds: [$seededId],
        )->assertCreated();

        $this->assertSeedPlacements($competition->id, [$seededId]);
        $this->assertSame([4, 4], $this->groupSizes($competition->id));
    }

    public function test_places_four_seeds_into_four_distinct_groups_in_request_order(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(12);
        $context->registerPlayers($competition, $players);
        $seededIds = $competition->entries()
            ->orderBy('id')
            ->limit(4)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $context->generateRandomGroups(
            $competition,
            groupsCount: 4,
            seededEntryIds: $seededIds,
        )->assertCreated();

        $this->assertSeedPlacements($competition->id, $seededIds);
        $this->assertSame([3, 3, 3, 3], $this->groupSizes($competition->id));
        $this->assertDatabaseCount('group_entries', 12);
    }

    public function test_two_seeds_with_fourteen_entries_keep_balanced_sizes(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(14);
        $context->registerPlayers($competition, $players);
        $seededIds = $competition->entries()
            ->orderBy('id')
            ->limit(2)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $context->generateRandomGroups(
            $competition,
            groupsCount: 4,
            seededEntryIds: $seededIds,
        )->assertCreated();

        $this->assertSeedPlacements($competition->id, $seededIds);
        $this->assertSame([3, 3, 4, 4], $this->groupSizes($competition->id));
    }

    public function test_rejects_more_seeds_than_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);
        $seededIds = $competition->entries()
            ->orderBy('id')
            ->limit(3)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $response = $context->generateRandomGroups(
            $competition,
            groupsCount: 2,
            seededEntryIds: $seededIds,
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['seeded_entry_ids'])
            ->assertJsonPath(
                'errors.seeded_entry_ids.0',
                SeededGroupEntriesGuard::tooManyMessage(3, 2),
            );

        $this->assertDatabaseCount('groups', 0);
        $this->assertDatabaseCount('group_entries', 0);
    }

    public function test_rejects_duplicate_seeded_entry_ids(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);
        $seededId = (int) $competition->entries()->orderBy('id')->value('id');

        $response = $context->generateRandomGroups(
            $competition,
            groupsCount: 2,
            seededEntryIds: [$seededId, $seededId],
        );

        $response->assertUnprocessable();

        $errorKeys = array_keys($response->json('errors') ?? []);
        $this->assertTrue(
            collect($errorKeys)->contains(
                fn (string $key): bool => str_starts_with($key, 'seeded_entry_ids'),
            ),
        );
        $this->assertDatabaseCount('groups', 0);
    }

    public function test_rejects_nonexistent_seeded_entry_id(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);

        $response = $context->generateRandomGroups(
            $competition,
            groupsCount: 2,
            seededEntryIds: [999_999],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['seeded_entry_ids']);

        $this->assertDatabaseCount('groups', 0);
    }

    public function test_rejects_seeded_entry_from_another_competition(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);

        $otherCompetition = $context->createCompetition();
        [$foreignPlayer] = $context->createPlayers(1);
        $foreignEntry = $context->registerPlayer($otherCompetition, $foreignPlayer);

        $response = $context->generateRandomGroups(
            $competition,
            groupsCount: 2,
            seededEntryIds: [(int) $foreignEntry->id],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['seeded_entry_ids']);

        $this->assertDatabaseCount('groups', 0);
    }

    public function test_rejects_withdrawn_seeded_entry(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);
        $withdrawn = $competition->entries()->orderBy('id')->firstOrFail();
        $withdrawn->update(['status' => CompetitionEntryStatus::Withdrawn]);

        $response = $context->generateRandomGroups(
            $competition,
            groupsCount: 2,
            seededEntryIds: [(int) $withdrawn->id],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['seeded_entry_ids']);

        $this->assertDatabaseCount('groups', 0);
    }

    public function test_rejects_disqualified_seeded_entry(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);
        $disqualified = $competition->entries()->orderBy('id')->firstOrFail();
        $disqualified->update(['status' => CompetitionEntryStatus::Disqualified]);

        $response = $context->generateRandomGroups(
            $competition,
            groupsCount: 2,
            seededEntryIds: [(int) $disqualified->id],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['seeded_entry_ids']);

        $this->assertDatabaseCount('groups', 0);
    }

    public function test_active_entry_without_check_in_can_be_seeded(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);
        $seededId = (int) $competition->entries()->orderBy('id')->value('id');

        $this->assertSame(
            0,
            CompetitionEntryMember::query()
                ->where('competition_id', $competition->id)
                ->whereNotNull('checked_in_at')
                ->count(),
        );

        $context->generateRandomGroups(
            $competition,
            groupsCount: 2,
            seededEntryIds: [$seededId],
        )->assertCreated();

        $this->assertSeedPlacements($competition->id, [$seededId]);
    }

    public function test_doubles_uses_competition_entry_ids_as_seeds(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(16);
        $pairs = [];

        for ($index = 0; $index < 8; $index++) {
            $pairs[] = [$players[$index * 2], $players[($index * 2) + 1]];
        }

        $entries = $context->registerPairs($competition, $pairs);
        $seededIds = array_map(
            static fn (CompetitionEntry $entry): int => (int) $entry->id,
            array_slice($entries, 0, 4),
        );

        $context->generateRandomGroups(
            $competition,
            groupsCount: 4,
            seededEntryIds: $seededIds,
        )->assertCreated();

        $this->assertSeedPlacements($competition->id, $seededIds);
        $this->assertSame([2, 2, 2, 2], $this->groupSizes($competition->id));
    }

    public function test_teams_uses_competition_entry_ids_as_seeds(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 8, 4);
        $seededIds = array_map(
            static fn (CompetitionEntry $entry): int => (int) $entry->id,
            array_slice($entries, 0, 4),
        );

        $context->generateRandomGroups(
            $competition,
            groupsCount: 4,
            seededEntryIds: $seededIds,
        )->assertCreated();

        $this->assertSeedPlacements($competition->id, $seededIds);
        $this->assertSame([2, 2, 2, 2], $this->groupSizes($competition->id));
    }

    public function test_regenerates_with_valid_seeds_and_keeps_them_apart(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(12);
        $context->registerPlayers($competition, $players);
        $context->generateRandomGroups($competition, groupsCount: 4)->assertCreated();

        $seededIds = $competition->entries()
            ->orderBy('id')
            ->limit(3)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $context->regenerateRandomGroups(
            $competition,
            groupsCount: 4,
            seededEntryIds: $seededIds,
        )->assertCreated();

        $this->assertSeedPlacements($competition->id, $seededIds);
        $this->assertSame([3, 3, 3, 3], $this->groupSizes($competition->id));
    }

    public function test_regeneration_with_seeds_includes_late_active_entry(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);
        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        [$latePlayer] = $context->createPlayers(1);
        $lateEntry = $context->registerPlayer($competition, $latePlayer);
        $seededId = (int) $competition->entries()->orderBy('id')->value('id');

        $context->regenerateRandomGroups(
            $competition,
            groupsCount: 3,
            seededEntryIds: [$seededId],
        )->assertCreated();

        $this->assertDatabaseHas('group_entries', [
            'competition_id' => $competition->id,
            'competition_entry_id' => $lateEntry->id,
        ]);
        $this->assertSeedPlacements($competition->id, [$seededId]);
        $this->assertSame(9, GroupEntry::query()->where('competition_id', $competition->id)->count());
    }

    public function test_invalid_seed_on_regenerate_does_not_wipe_existing_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);
        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $originalGroupIds = Group::query()
            ->where('competition_id', $competition->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $originalGroupEntryIds = GroupEntry::query()
            ->where('competition_id', $competition->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $originalGameIds = Game::query()
            ->where('competition_id', $competition->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $response = $context->regenerateRandomGroups(
            $competition,
            groupsCount: 2,
            seededEntryIds: [999_999],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['seeded_entry_ids']);

        $this->assertSame(
            $originalGroupIds,
            Group::query()->where('competition_id', $competition->id)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            $originalGroupEntryIds,
            GroupEntry::query()->where('competition_id', $competition->id)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            $originalGameIds,
            Game::query()->where('competition_id', $competition->id)->orderBy('id')->pluck('id')->all(),
        );
    }

    /**
     * @return list<int>
     */
    private function groupSizes(int $competitionId): array
    {
        return GroupEntry::query()
            ->where('competition_id', $competitionId)
            ->selectRaw('group_id, count(*) as total')
            ->groupBy('group_id')
            ->pluck('total')
            ->sort()
            ->values()
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * @param  list<int>  $seededIds
     */
    private function assertSeedPlacements(int $competitionId, array $seededIds): void
    {
        $groups = Group::query()
            ->where('competition_id', $competitionId)
            ->orderBy('id')
            ->get()
            ->values();

        $groupIdsByEntryId = GroupEntry::query()
            ->where('competition_id', $competitionId)
            ->whereIn('competition_entry_id', $seededIds)
            ->pluck('group_id', 'competition_entry_id');

        $this->assertCount(count($seededIds), $groupIdsByEntryId->unique());

        foreach ($seededIds as $index => $seededId) {
            $this->assertSame(
                (int) $groups[$index]->id,
                (int) $groupIdsByEntryId->get($seededId),
            );
        }
    }
}
