<?php

namespace Tests\Feature\Group;

use App\Actions\Group\BuildPrintGroupSheetAction;
use App\Enums\CompetitionEntryStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\Group;
use App\Support\Competition\CompetitionEntryDisplayName;
use App\Support\Group\GroupFixtureOrder;
use App\Support\Group\GroupSheetNumbering;
use Tests\TestCase;

class GroupPrintTest extends TestCase
{
    public function test_singles_group_with_fixture_returns_print_sheet(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 4, setsToWin: 2);

        $response = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"));

        $response
            ->assertOk()
            ->assertJsonPath('data.tournament.name', 'Torneo Test')
            ->assertJsonPath('data.competition.id', $setup['competition']->id)
            ->assertJsonPath('data.competition.type', 'singles')
            ->assertJsonPath('data.group.id', $setup['group']->id)
            ->assertJsonPath('data.group.name', 'Grupo A')
            ->assertJsonPath('data.best_of', 3)
            ->assertJsonPath('data.sets_to_win', 2)
            ->assertJsonPath('data.points_per_set', 11)
            ->assertJsonPath('data.qualified_per_group', 2)
            ->assertJsonCount(4, 'data.participants')
            ->assertJsonCount(6, 'data.matches');

        $this->assertSame(
            range(1, 6),
            collect($response->json('data.matches'))->pluck('order')->all(),
        );
    }

    public function test_participants_use_display_name_not_legacy_player_fields(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 2);

        $response = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"));

        $response->assertOk();

        $participant = $response->json('data.participants.0');
        $this->assertArrayHasKey('competition_entry_id', $participant);
        $this->assertArrayHasKey('sheet_number', $participant);
        $this->assertArrayHasKey('display_name', $participant);
        $this->assertArrayHasKey('members', $participant);
        $this->assertArrayNotHasKey('player_id', $participant);
        $this->assertArrayNotHasKey('player_name', $participant);
        $this->assertArrayNotHasKey('id', $participant);
        $this->assertNotSame('', $participant['display_name']);
    }

    public function test_matches_are_ordered_by_group_round_and_group_match(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 4, setsToWin: 2);

        $payload = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->json('data.matches');

        $sorted = collect($payload)
            ->sortBy([
                ['group_round', 'asc'],
                ['group_match', 'asc'],
                ['game_id', 'asc'],
            ])
            ->values()
            ->all();

        $this->assertSame(
            collect($sorted)->pluck('game_id')->all(),
            collect($payload)->pluck('game_id')->all(),
        );
    }

    public function test_group_of_three_or_more_has_referee_who_is_not_playing(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 4, setsToWin: 2);

        $matches = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->json('data.matches');

        foreach ($matches as $match) {
            $this->assertNotNull($match['referee']);
            $this->assertNotSame(
                $match['side1']['competition_entry_id'],
                $match['referee']['competition_entry_id'],
            );
            $this->assertNotSame(
                $match['side2']['competition_entry_id'],
                $match['referee']['competition_entry_id'],
            );
        }
    }

    public function test_group_of_two_returns_null_referee(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 2, setsToWin: 2);

        $matches = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->json('data.matches');

        $this->assertCount(1, $matches);
        $this->assertNull($matches[0]['referee']);
    }

    public function test_best_of_three_snapshot_is_exposed_on_header_and_matches(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 2);

        $response = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"));

        $response
            ->assertOk()
            ->assertJsonPath('data.best_of', 3)
            ->assertJsonPath('data.sets_to_win', 2);

        foreach ($response->json('data.matches') as $match) {
            $this->assertSame(3, $match['best_of']);
            $this->assertSame(2, $match['sets_to_win']);
        }
    }

    public function test_best_of_five_snapshot_is_exposed_on_header_and_matches(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 3);

        $response = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"));

        $response
            ->assertOk()
            ->assertJsonPath('data.best_of', 5)
            ->assertJsonPath('data.sets_to_win', 3);

        foreach ($response->json('data.matches') as $match) {
            $this->assertSame(5, $match['best_of']);
            $this->assertSame(3, $match['sets_to_win']);
        }
    }

    public function test_round_robin_games_share_the_same_best_of(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 4, setsToWin: 2);

        $bestOfs = Game::query()
            ->where('group_id', $setup['group']->id)
            ->pluck('best_of')
            ->unique()
            ->values();

        $this->assertCount(1, $bestOfs);
        $this->assertSame(3, (int) $bestOfs->first());
    }

    public function test_doubles_display_name_uses_pair_separator(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition(setsToWin: 2);
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

        $context->generateRoundRobin($group)->assertCreated();

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/print"));
        $response
            ->assertOk()
            ->assertJsonPath('data.competition.type', 'doubles');

        $firstParticipant = $response->json('data.participants.0');
        $this->assertStringContainsString(' / ', $firstParticipant['display_name']);
        $this->assertSame(
            CompetitionEntryDisplayName::for($entries[0]->fresh(['members.player'])),
            collect($response->json('data.participants'))
                ->firstWhere('competition_entry_id', $entries[0]->id)['display_name'],
        );

        $firstMatch = $response->json('data.matches.0');
        $this->assertStringContainsString(' / ', $firstMatch['side1']['display_name']);
        $this->assertStringContainsString(' / ', $firstMatch['side2']['display_name']);
        $this->assertNotNull($firstMatch['referee']);
        $this->assertStringContainsString(' / ', $firstMatch['referee']['display_name']);
    }

    public function test_group_without_fixture_returns_unprocessable(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $this->getJson($context->apiUrl("groups/{$group->id}/print"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group'])
            ->assertJsonPath(
                'errors.group.0',
                'Los partidos del round robin aún no fueron generados.',
            );
    }

    public function test_missing_group_returns_not_found(): void
    {
        $context = $this->tournamentContext();

        $this->getJson($context->apiUrl('groups/999999/print'))
            ->assertNotFound();
    }

    public function test_team_group_returns_unprocessable(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 4, 4);
        $group = $context->createGroupWithEntries($competition, $entries);

        $this->getJson($context->apiUrl("groups/{$group->id}/print"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group'])
            ->assertJsonPath(
                'errors.group.0',
                'La impresión de grupos por equipos todavía no está disponible.',
            );
    }

    public function test_print_response_is_deterministic(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 4, setsToWin: 2);

        $first = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->json('data');
        $second = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame($first, $second);
    }

    public function test_finished_games_keep_the_same_referees_and_omit_scores(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 2);

        $before = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->json('data');

        $games = Game::query()->where('group_id', $setup['group']->id)->get();

        foreach ($games as $game) {
            $context->finishGameByEntry($game, (int) $game->entry1_id);
        }

        $after = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame(
            collect($before['matches'])->pluck('referee')->all(),
            collect($after['matches'])->pluck('referee')->all(),
        );

        foreach ($after['matches'] as $match) {
            $this->assertArrayNotHasKey('sets', $match);
            $this->assertArrayNotHasKey('winner_entry_id', $match);
            $this->assertArrayNotHasKey('status', $match);
        }

        $this->assertOperationalMatrix($after['matrix'], size: 3);
        $this->assertSame($before['matrix'], $after['matrix']);
    }

    public function test_print_endpoint_remains_public(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 2);

        $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk();
    }

    public function test_g3_sheet_uses_playing_order_orientation_referees_and_matrix(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 2);

        $payload = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame('g3', $payload['sheet_kind']);
        $this->assertSame([1, 2, 3], array_column($payload['participants'], 'sheet_number'));
        $this->assertCount(3, $payload['matches']);
        $this->assertSame([[1, 3], [1, 2], [2, 3]], $this->pairings($payload['matches']));
        $this->assertSame([1, 2, 3], array_column($payload['matches'], 'group_round'));
        $this->assertSame([1, 1, 1], array_column($payload['matches'], 'group_match'));
        $this->assertSame([1, 2, 3], array_column($payload['matches'], 'order'));
        $this->assertSame([2, 3, 1], array_column($payload['matches'], 'referee_number'));
        $this->assertSidesFollowSheetNumbers($payload['matches']);
        $this->assertRefereesAreNotPlaying($payload['matches']);
        $this->assertOperationalMatrix($payload['matrix'], size: 3);
        $this->assertNoSportingResults($payload);
        $this->assertSame(
            GroupFixtureOrder::games($setup['group'])->pluck('id')->all(),
            array_column($payload['matches'], 'game_id'),
        );
    }

    public function test_g4_sheet_uses_playing_order_rounds_and_matrix(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 4, setsToWin: 2);

        $payload = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame('g4', $payload['sheet_kind']);
        $this->assertSame([1, 2, 3, 4], array_column($payload['participants'], 'sheet_number'));
        $this->assertCount(6, $payload['matches']);
        $this->assertSame(
            [[1, 3], [2, 4], [1, 2], [3, 4], [1, 4], [2, 3]],
            $this->pairings($payload['matches']),
        );
        $this->assertSame([1, 1, 2, 2, 3, 3], array_column($payload['matches'], 'group_round'));
        $this->assertSame([1, 2, 1, 2, 1, 2], array_column($payload['matches'], 'group_match'));
        $this->assertSidesFollowSheetNumbers($payload['matches']);
        $this->assertRefereesAreNotPlaying($payload['matches']);
        $this->assertRefereeCountsDifferByAtMostOne($payload['matches']);
        $this->assertOperationalMatrix($payload['matrix'], size: 4);
        $this->assertNoSportingResults($payload);
    }

    public function test_g5_sheet_uses_playing_order_and_matrix(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 5, setsToWin: 2);

        $payload = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame('g5', $payload['sheet_kind']);
        $this->assertSame([1, 2, 3, 4, 5], array_column($payload['participants'], 'sheet_number'));
        $this->assertCount(10, $payload['matches']);
        $this->assertSame(
            [[2, 5], [3, 4], [1, 5], [2, 3], [1, 4], [5, 3], [1, 3], [4, 2], [1, 2], [4, 5]],
            $this->pairings($payload['matches']),
        );
        $this->assertSame([1, 1, 2, 2, 3, 3, 4, 4, 5, 5], array_column($payload['matches'], 'group_round'));
        $this->assertSame([1, 2, 1, 2, 1, 2, 1, 2, 1, 2], array_column($payload['matches'], 'group_match'));
        $this->assertSidesFollowSheetNumbers($payload['matches']);
        $this->assertRefereesAreNotPlaying($payload['matches']);
        $this->assertRefereeCountsDifferByAtMostOne($payload['matches']);
        $this->assertOperationalMatrix($payload['matrix'], size: 5);
        $this->assertNoSportingResults($payload);
    }

    public function test_g5_keeps_sheet_orientation_when_persisted_sides_are_inverted(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 5, setsToWin: 2);

        $entryIds = $setup['group']->groupEntries()->pluck('competition_entry_id')->map(fn ($id): int => (int) $id)->all();
        $sheetNumberByEntryId = GroupSheetNumbering::forCompetitionEntryIds($entryIds);
        $entryIdBySheetNumber = array_flip($sheetNumberByEntryId);
        $entryTwoId = (int) $entryIdBySheetNumber[2];
        $entryFourId = (int) $entryIdBySheetNumber[4];

        $game = Game::query()
            ->where('group_id', $setup['group']->id)
            ->where(function ($query) use ($entryTwoId, $entryFourId): void {
                $query->where(function ($query) use ($entryTwoId, $entryFourId): void {
                    $query->where('entry1_id', $entryTwoId)->where('entry2_id', $entryFourId);
                })->orWhere(function ($query) use ($entryTwoId, $entryFourId): void {
                    $query->where('entry1_id', $entryFourId)->where('entry2_id', $entryTwoId);
                });
            })
            ->firstOrFail();

        $game->update([
            'entry1_id' => $entryTwoId,
            'entry2_id' => $entryFourId,
        ]);

        $payload = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->json('data');

        $match = collect($payload['matches'])->first(
            fn (array $item): bool => $item['side1_number'] === 4 && $item['side2_number'] === 2,
        );

        $this->assertNotNull($match);
        $this->assertSame((int) $game->id, $match['game_id']);
        $this->assertSame($entryFourId, $match['side1']['competition_entry_id']);
        $this->assertSame($entryTwoId, $match['side2']['competition_entry_id']);
        $this->assertSame(
            [[2, 5], [3, 4], [1, 5], [2, 3], [1, 4], [5, 3], [1, 3], [4, 2], [1, 2], [4, 5]],
            $this->pairings($payload['matches']),
        );

        $game->refresh();
        $this->assertSame($entryTwoId, (int) $game->entry1_id);
        $this->assertSame($entryFourId, (int) $game->entry2_id);
    }

    public function test_sheet_numbers_follow_non_contiguous_competition_entry_ids(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition(2);
        $players = $context->createPlayers(4);
        $ids = [90, 12, 35, 28];
        $entries = [];

        foreach ($players as $index => $player) {
            $entries[] = $this->createSinglesEntryWithId($competition, $player, $ids[$index]);
        }

        $group = $context->createGroup($competition);

        foreach ($entries as $entry) {
            $context->assignEntryToGroupViaApi($group, $entry)->assertCreated();
        }

        $context->generateRoundRobin($group)->assertCreated();

        $payload = $this->getJson($context->apiUrl("groups/{$group->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame(
            [
                12 => 1,
                28 => 2,
                35 => 3,
                90 => 4,
            ],
            collect($payload['participants'])->mapWithKeys(
                fn (array $participant): array => [
                    $participant['competition_entry_id'] => $participant['sheet_number'],
                ],
            )->all(),
        );
        $this->assertSame([1, 2, 3, 4], array_column($payload['participants'], 'sheet_number'));
        $this->assertSame('g4', $payload['sheet_kind']);
    }

    public function test_doubles_g3_keeps_pair_display_names_and_entry_referee(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition(setsToWin: 2);
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

        $context->generateRoundRobin($group)->assertCreated();

        $payload = $this->getJson($context->apiUrl("groups/{$group->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame('g3', $payload['sheet_kind']);
        $this->assertSame([1, 2, 3], array_column($payload['participants'], 'sheet_number'));
        $this->assertSame([[1, 3], [1, 2], [2, 3]], $this->pairings($payload['matches']));

        foreach ($payload['participants'] as $participant) {
            $this->assertStringContainsString(' / ', $participant['display_name']);
        }

        foreach ($payload['matches'] as $match) {
            $this->assertStringContainsString(' / ', $match['side1']['display_name']);
            $this->assertStringContainsString(' / ', $match['side2']['display_name']);
            $this->assertNotNull($match['referee']);
            $this->assertStringContainsString(' / ', $match['referee']['display_name']);
            $this->assertContains(
                $match['referee']['competition_entry_id'],
                collect($entries)->map(fn ($entry): int => (int) $entry->id)->all(),
            );
        }
    }

    public function test_group_of_two_uses_generic_sheet_kind(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 2, setsToWin: 2);

        $payload = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame('generic', $payload['sheet_kind']);
        $this->assertSame([1, 2], array_column($payload['participants'], 'sheet_number'));
        $this->assertCount(1, $payload['matches']);
        $this->assertNull($payload['matches'][0]['referee']);
        $this->assertNull($payload['matches'][0]['referee_number']);
        $this->assertOperationalMatrix($payload['matrix'], size: 2);
        $this->assertSame(
            GroupFixtureOrder::games($setup['group'])->pluck('id')->all(),
            array_column($payload['matches'], 'game_id'),
        );
    }

    public function test_group_of_six_uses_generic_fixture_order(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 6, setsToWin: 2);

        $payload = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame('generic', $payload['sheet_kind']);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($payload['participants'], 'sheet_number'));
        $this->assertCount(15, $payload['matches']);
        $this->assertSame(
            GroupFixtureOrder::games($setup['group'])->pluck('id')->all(),
            array_column($payload['matches'], 'game_id'),
        );
        $this->assertOperationalMatrix($payload['matrix'], size: 6);
        $this->assertRefereesAreNotPlaying($payload['matches']);
    }

    public function test_g3_incomplete_fixture_returns_unprocessable(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 2);

        Game::query()->where('group_id', $setup['group']->id)->orderBy('id')->first()?->delete();

        $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group'])
            ->assertJsonPath(
                'errors.group.0',
                BuildPrintGroupSheetAction::INVALID_FIXTURE_MESSAGE,
            );
    }

    /**
     * @return array{competition: Competition, group: Group}
     */
    private function createSinglesGroup($context, int $playerCount, int $setsToWin): array
    {
        $competition = $context->createCompetition($setsToWin);
        $players = $context->createPlayers($playerCount);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        return [
            'competition' => $competition,
            'group' => $group,
        ];
    }

    private function createSinglesEntryWithId($competition, $player, int $id): CompetitionEntry
    {
        $entry = CompetitionEntry::query()->create([
            'id' => $id,
            'competition_id' => $competition->id,
            'status' => CompetitionEntryStatus::Active,
        ]);

        CompetitionEntryMember::query()->create([
            'competition_entry_id' => $entry->id,
            'competition_id' => $competition->id,
            'player_id' => $player->id,
            'member_order' => 1,
        ]);

        return $entry->load('members.player');
    }

    /**
     * @param  list<array{side1_number: int|null, side2_number: int|null}>  $matches
     * @return list<array{0: int|null, 1: int|null}>
     */
    private function pairings(array $matches): array
    {
        return array_map(
            static fn (array $match): array => [$match['side1_number'], $match['side2_number']],
            $matches,
        );
    }

    /**
     * @param  list<array{side1_number: int|null, side2_number: int|null, side1: array{sheet_number: int|null}|null, side2: array{sheet_number: int|null}|null}>  $matches
     */
    private function assertSidesFollowSheetNumbers(array $matches): void
    {
        foreach ($matches as $match) {
            $this->assertSame($match['side1_number'], $match['side1']['sheet_number']);
            $this->assertSame($match['side2_number'], $match['side2']['sheet_number']);
        }
    }

    /**
     * @param  list<array{side1: array{competition_entry_id: int}|null, side2: array{competition_entry_id: int}|null, referee: array{competition_entry_id: int}|null}>  $matches
     */
    private function assertRefereesAreNotPlaying(array $matches): void
    {
        foreach ($matches as $match) {
            $this->assertNotNull($match['referee']);
            $this->assertNotSame(
                $match['side1']['competition_entry_id'],
                $match['referee']['competition_entry_id'],
            );
            $this->assertNotSame(
                $match['side2']['competition_entry_id'],
                $match['referee']['competition_entry_id'],
            );
        }
    }

    /**
     * @param  list<array{referee_number: int|null}>  $matches
     */
    private function assertRefereeCountsDifferByAtMostOne(array $matches): void
    {
        $counts = array_count_values(array_filter(
            array_column($matches, 'referee_number'),
            static fn ($number): bool => $number !== null,
        ));

        $this->assertNotEmpty($counts);
        $this->assertLessThanOrEqual(1, max($counts) - min($counts));
    }

    /**
     * @param  list<array{sheet_number: int, cells: list<array{opponent_number: int, type: string, game_id: int|null}>}>  $matrix
     */
    private function assertOperationalMatrix(array $matrix, int $size): void
    {
        $this->assertCount($size, $matrix);

        foreach ($matrix as $rowIndex => $row) {
            $this->assertSame($rowIndex + 1, $row['sheet_number']);
            $this->assertCount($size, $row['cells']);
            $this->assertSame($row['sheet_number'], $row['entry']['sheet_number']);

            foreach ($row['cells'] as $cellIndex => $cell) {
                $this->assertSame($cellIndex + 1, $cell['opponent_number']);
                $this->assertContains($cell['type'], ['self', 'match']);
                $this->assertArrayNotHasKey('sets', $cell);
                $this->assertArrayNotHasKey('score', $cell);
                $this->assertArrayNotHasKey('winner_entry_id', $cell);

                if ($cell['opponent_number'] === $row['sheet_number']) {
                    $this->assertSame('self', $cell['type']);
                    $this->assertNull($cell['game_id']);
                } else {
                    $this->assertSame('match', $cell['type']);
                }
            }
        }
    }

    /**
     * @param  array{matches: list<array<string, mixed>>, matrix: list<array<string, mixed>>}  $payload
     */
    private function assertNoSportingResults(array $payload): void
    {
        foreach ($payload['matches'] as $match) {
            $this->assertArrayNotHasKey('sets', $match);
            $this->assertArrayNotHasKey('winner_entry_id', $match);
            $this->assertArrayNotHasKey('status', $match);
            $this->assertArrayNotHasKey('winner', $match);
        }
    }
}
