<?php

namespace Tests\Feature\Competition;

use App\Actions\Group\BuildCompetitionGroupsPrintAction;
use App\Actions\Group\BuildPrintGroupSheetAction;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Group;
use App\Support\Competition\CompetitionEntryDisplayName;
use Tests\TestCase;

class CompetitionGroupsPrintTest extends TestCase
{
    public function test_singles_competition_with_two_groups_returns_print_sheets(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesCompetitionWithGroups($context, playerCountPerGroup: 4, setsToWin: 2);

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/groups/print"));

        $response
            ->assertOk()
            ->assertJsonPath('data.tournament.name', 'Torneo Test')
            ->assertJsonPath('data.competition.id', $setup['competition']->id)
            ->assertJsonPath('data.competition.type', 'singles')
            ->assertJsonPath('data.groups_count', 2)
            ->assertJsonCount(2, 'data.sheets');

        $this->assertSame(
            ['Grupo A', 'Grupo B'],
            collect($response->json('data.sheets'))->pluck('group.name')->all(),
        );

        foreach ($response->json('data.sheets') as $sheet) {
            $this->assertNotEmpty($sheet['matches']);
            $this->assertSame(3, $sheet['best_of']);
            $this->assertSame(2, $sheet['sets_to_win']);
        }
    }

    public function test_sheets_are_ordered_by_group_name_not_creation_order(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition(2);
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $groupB = $context->createGroupWithPlayers($competition, array_slice($players, 0, 3), 'Grupo B');
        $groupA = $context->createGroupWithPlayers($competition, array_slice($players, 3, 3), 'Grupo A');
        $context->generateRoundRobin($groupB)->assertCreated();
        $context->generateRoundRobin($groupA)->assertCreated();

        $names = $this->getJson($context->apiUrl("competitions/{$competition->id}/groups/print"))
            ->assertOk()
            ->json('data.sheets');

        $this->assertSame(
            ['Grupo A', 'Grupo B'],
            collect($names)->pluck('group.name')->all(),
        );
        $this->assertSame(
            [$groupA->id, $groupB->id],
            collect($names)->pluck('group.id')->all(),
        );
    }

    public function test_groups_of_three_or_more_include_referees(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesCompetitionWithGroups($context, playerCountPerGroup: 4, setsToWin: 2);

        $sheets = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/groups/print"))
            ->assertOk()
            ->json('data.sheets');

        foreach ($sheets as $sheet) {
            foreach ($sheet['matches'] as $match) {
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
    }

    public function test_bulk_referees_match_individual_print_endpoint(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesCompetitionWithGroups($context, playerCountPerGroup: 4, setsToWin: 2);

        $bulkSheets = collect(
            $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/groups/print"))
                ->assertOk()
                ->json('data.sheets'),
        )->keyBy('group.id');

        foreach ([$setup['groupA'], $setup['groupB']] as $group) {
            $individual = $this->getJson($context->apiUrl("groups/{$group->id}/print"))
                ->assertOk()
                ->json('data');
            $bulk = $bulkSheets->get($group->id);

            $this->assertNotNull($bulk);
            $this->assertSame(
                collect($individual['matches'])->pluck('referee')->all(),
                collect($bulk['matches'])->pluck('referee')->all(),
            );
            $this->assertSame(
                collect($individual['matches'])->pluck('game_id')->all(),
                collect($bulk['matches'])->pluck('game_id')->all(),
            );
        }
    }

    public function test_doubles_competition_is_supported(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition(setsToWin: 2);
        $players = $context->createPlayers(12);
        $entries = $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
            [$players[6], $players[7]],
            [$players[8], $players[9]],
            [$players[10], $players[11]],
        ]);

        $groupA = $context->createGroupWithEntries($competition, array_slice($entries, 0, 3), 'Grupo A');
        $groupB = $context->createGroupWithEntries($competition, array_slice($entries, 3, 3), 'Grupo B');
        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/groups/print"));
        $response
            ->assertOk()
            ->assertJsonPath('data.competition.type', 'doubles')
            ->assertJsonPath('data.groups_count', 2);

        $firstParticipant = $response->json('data.sheets.0.participants.0');
        $this->assertStringContainsString(' / ', $firstParticipant['display_name']);
        $this->assertSame(
            CompetitionEntryDisplayName::for($entries[0]->fresh(['members.player'])),
            collect($response->json('data.sheets.0.participants'))
                ->firstWhere('competition_entry_id', $entries[0]->id)['display_name'],
        );
    }

    public function test_team_competition_returns_unprocessable(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 4, 4);
        $context->createGroupWithEntries($competition, $entries);

        $this->getJson($context->apiUrl("competitions/{$competition->id}/groups/print"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath(
                'errors.competition.0',
                BuildPrintGroupSheetAction::TEAM_NOT_AVAILABLE_MESSAGE,
            );
    }

    public function test_competition_without_groups_returns_unprocessable(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $this->getJson($context->apiUrl("competitions/{$competition->id}/groups/print"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath(
                'errors.competition.0',
                BuildCompetitionGroupsPrintAction::WITHOUT_GROUPS_MESSAGE,
            );
    }

    public function test_group_without_fixture_returns_unprocessable_and_lists_missing_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition(2);
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $groupA = $context->createGroupWithPlayers($competition, array_slice($players, 0, 3), 'Grupo A');
        $groupB = $context->createGroupWithPlayers($competition, array_slice($players, 3, 3), 'Grupo B');
        $context->generateRoundRobin($groupA)->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/groups/print"));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath(
                'errors.competition.0',
                BuildCompetitionGroupsPrintAction::GROUPS_WITHOUT_SCHEDULE_MESSAGE,
            )
            ->assertJsonPath('groups_without_schedule.0.id', $groupB->id)
            ->assertJsonPath('groups_without_schedule.0.name', 'Grupo B')
            ->assertJsonCount(1, 'groups_without_schedule')
            ->assertJsonMissingPath('data.sheets');
    }

    public function test_print_response_is_deterministic(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesCompetitionWithGroups($context, playerCountPerGroup: 3, setsToWin: 2);

        $first = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/groups/print"))
            ->assertOk()
            ->json('data');
        $second = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/groups/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame($first, $second);
    }

    public function test_finished_games_keep_the_same_referees_and_omit_scores(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesCompetitionWithGroups($context, playerCountPerGroup: 3, setsToWin: 2);

        $before = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/groups/print"))
            ->assertOk()
            ->json('data');

        $games = Game::query()
            ->whereIn('group_id', [$setup['groupA']->id, $setup['groupB']->id])
            ->get();

        foreach ($games as $game) {
            $context->finishGameByEntry($game, (int) $game->entry1_id);
        }

        $after = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/groups/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame(
            collect($before['sheets'])->map(fn (array $sheet) => collect($sheet['matches'])->pluck('referee')->all())->all(),
            collect($after['sheets'])->map(fn (array $sheet) => collect($sheet['matches'])->pluck('referee')->all())->all(),
        );

        foreach ($after['sheets'] as $sheet) {
            foreach ($sheet['matches'] as $match) {
                $this->assertArrayNotHasKey('sets', $match);
                $this->assertArrayNotHasKey('winner_entry_id', $match);
                $this->assertArrayNotHasKey('status', $match);
            }
        }
    }

    public function test_best_of_three_is_exposed_on_every_sheet(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesCompetitionWithGroups($context, playerCountPerGroup: 3, setsToWin: 2);

        $sheets = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/groups/print"))
            ->assertOk()
            ->json('data.sheets');

        foreach ($sheets as $sheet) {
            $this->assertSame(3, $sheet['best_of']);
            $this->assertSame(2, $sheet['sets_to_win']);

            foreach ($sheet['matches'] as $match) {
                $this->assertSame(3, $match['best_of']);
                $this->assertSame(2, $match['sets_to_win']);
            }
        }
    }

    public function test_best_of_five_is_exposed_on_every_sheet(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesCompetitionWithGroups($context, playerCountPerGroup: 3, setsToWin: 3);

        $sheets = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/groups/print"))
            ->assertOk()
            ->json('data.sheets');

        foreach ($sheets as $sheet) {
            $this->assertSame(5, $sheet['best_of']);
            $this->assertSame(3, $sheet['sets_to_win']);

            foreach ($sheet['matches'] as $match) {
                $this->assertSame(5, $match['best_of']);
                $this->assertSame(3, $match['sets_to_win']);
            }
        }
    }

    public function test_print_endpoint_remains_public(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesCompetitionWithGroups($context, playerCountPerGroup: 3, setsToWin: 2);

        $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/groups/print"))
            ->assertOk();
    }

    /**
     * @return array{competition: Competition, groupA: Group, groupB: Group}
     */
    private function createSinglesCompetitionWithGroups(
        $context,
        int $playerCountPerGroup,
        int $setsToWin,
    ): array {
        $competition = $context->createCompetition($setsToWin);
        $players = $context->createPlayers($playerCountPerGroup * 2);
        $context->registerPlayers($competition, $players);

        $groupA = $context->createGroupWithPlayers(
            $competition,
            array_slice($players, 0, $playerCountPerGroup),
            'Grupo A',
        );
        $groupB = $context->createGroupWithPlayers(
            $competition,
            array_slice($players, $playerCountPerGroup, $playerCountPerGroup),
            'Grupo B',
        );

        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();

        return [
            'competition' => $competition,
            'groupA' => $groupA,
            'groupB' => $groupB,
        ];
    }
}
