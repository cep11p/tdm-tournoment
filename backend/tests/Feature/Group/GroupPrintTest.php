<?php

namespace Tests\Feature\Group;

use App\Models\Competition;
use App\Models\Game;
use App\Models\Group;
use App\Support\Competition\CompetitionEntryDisplayName;
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
    }

    public function test_print_endpoint_remains_public(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 2);

        $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk();
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
}
