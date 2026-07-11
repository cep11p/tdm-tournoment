<?php

namespace Tests\Feature\Group;

use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupPlayer;
use App\Support\Group\RandomGroupDistributionGuard;
use Tests\TestCase;

class GenerateRandomGroupsTest extends TestCase
{
    public function test_generates_five_balanced_groups_with_twenty_players(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(20);
        $context->registerPlayers($competition, $players);

        $response = $context->generateRandomGroups($competition, groupsCount: 5);

        $response
            ->assertCreated()
            ->assertJson([
                'message' => 'Grupos generados correctamente.',
                'groups_created' => 5,
                'players_assigned' => 20,
            ])
            ->assertJsonCount(5, 'groups');

        $this->assertDatabaseCount('groups', 5);
        $this->assertDatabaseCount('group_players', 20);

        $playersPerGroup = GroupPlayer::query()
            ->selectRaw('group_id, count(*) as total')
            ->groupBy('group_id')
            ->pluck('total')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([4, 4, 4, 4, 4], $playersPerGroup);
    }

    public function test_generates_four_balanced_groups_with_fourteen_players(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(14);
        $context->registerPlayers($competition, $players);

        $response = $context->generateRandomGroups($competition, groupsCount: 4);

        $response
            ->assertCreated()
            ->assertJson([
                'groups_created' => 4,
                'players_assigned' => 14,
            ]);

        $playersPerGroup = GroupPlayer::query()
            ->selectRaw('group_id, count(*) as total')
            ->groupBy('group_id')
            ->pluck('total')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([3, 3, 4, 4], $playersPerGroup);
    }

    public function test_fails_when_competition_has_no_registrations(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $response = $context->generateRandomGroups($competition, groupsCount: 2);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);
    }

    public function test_fails_when_competition_has_only_one_registration(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $context->registerPlayer($competition, $player);

        $response = $context->generateRandomGroups($competition, groupsCount: 1);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);
    }

    public function test_fails_when_groups_count_exceeds_registered_players(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);

        $response = $context->generateRandomGroups($competition, groupsCount: 4);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['groups_count']);
    }

    public function test_fails_when_competition_already_has_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createGroup($competition, 'Grupo A');

        $response = $context->generateRandomGroups($competition, groupsCount: 2);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);
    }

    public function test_fails_when_competition_already_has_group_games(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $response = $context->generateRandomGroups($competition, groupsCount: 2);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);
    }

    public function test_fails_when_competition_already_has_bracket(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        $response = $context->generateRandomGroups($setup['competition'], groupsCount: 2);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);
    }

    public function test_generates_round_robin_games_for_each_group(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $response = $context->generateRandomGroups($competition, groupsCount: 2);

        $response
            ->assertCreated()
            ->assertJson([
                'games_created' => 6,
            ]);

        $this->assertDatabaseCount('games', 6);

        $groups = Group::query()
            ->where('competition_id', $competition->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $groups);

        foreach ($groups as $group) {
            $this->assertSame(3, Game::query()->where('group_id', $group->id)->count());
        }

        $this->assertGamesHaveExpectedAttributes($competition->id, $groups);
    }

    public function test_cannot_generate_groups_when_one_group_would_have_only_one_player(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(7);
        $context->registerPlayers($competition, $players);

        $response = $context->generateRandomGroups($competition, groupsCount: 4);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['groups_count'])
            ->assertJsonPath(
                'errors.groups_count.0',
                RandomGroupDistributionGuard::validationMessage(7, 4),
            );

        $this->assertDatabaseCount('groups', 0);
        $this->assertDatabaseCount('group_players', 0);
        $this->assertDatabaseCount('games', 0);
    }

    public function test_seven_players_cannot_be_distributed_into_four_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(7);
        $context->registerPlayers($competition, $players);

        $response = $context->generateRandomGroups($competition, groupsCount: 4);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['groups_count']);
    }

    public function test_seven_players_can_be_distributed_into_three_groups_as_3_2_2(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(7);
        $context->registerPlayers($competition, $players);

        $response = $context->generateRandomGroups($competition, groupsCount: 3);

        $response
            ->assertCreated()
            ->assertJson([
                'groups_created' => 3,
                'players_assigned' => 7,
                'games_created' => 5,
            ]);

        $playersPerGroup = GroupPlayer::query()
            ->selectRaw('group_id, count(*) as total')
            ->groupBy('group_id')
            ->pluck('total')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([2, 2, 3], $playersPerGroup);
    }

    public function test_group_generation_never_creates_single_player_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);

        $response = $context->generateRandomGroups($competition, groupsCount: 4);

        $response->assertCreated();

        $singlePlayerGroups = GroupPlayer::query()
            ->selectRaw('group_id, count(*) as total')
            ->groupBy('group_id')
            ->having('total', '<', 2)
            ->count();

        $this->assertSame(0, $singlePlayerGroups);
        $this->assertSame(4, Game::query()->where('competition_id', $competition->id)->count());
    }

    public function test_generates_thirty_games_for_twenty_players_in_five_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(20);
        $context->registerPlayers($competition, $players);

        $response = $context->generateRandomGroups($competition, groupsCount: 5);

        $response
            ->assertCreated()
            ->assertJson([
                'games_created' => 30,
            ]);

        $this->assertDatabaseCount('games', 30);

        $groups = Group::query()
            ->where('competition_id', $competition->id)
            ->orderBy('id')
            ->get();

        foreach ($groups as $group) {
            $this->assertSame(6, Game::query()->where('group_id', $group->id)->count());
        }

        $this->assertGamesHaveExpectedAttributes($competition->id, $groups);
    }

    public function test_creates_group_players_for_all_registered_players(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $assignedPlayerIds = GroupPlayer::query()
            ->whereHas('group', fn ($query) => $query->where('competition_id', $competition->id))
            ->pluck('player_id')
            ->sort()
            ->values()
            ->all();

        $expectedPlayerIds = collect($players)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expectedPlayerIds, $assignedPlayerIds);
    }

    public function test_response_includes_expected_json_structure(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $response = $context->generateRandomGroups($competition, groupsCount: 2);

        $response
            ->assertCreated()
            ->assertJsonStructure([
                'message',
                'groups_created',
                'players_assigned',
                'games_created',
                'groups' => [
                    '*' => [
                        'id',
                        'competition_id',
                        'name',
                        'group_players',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Group>|\Illuminate\Database\Eloquent\Collection<int, Group>  $groups
     */
    private function assertGamesHaveExpectedAttributes(int $competitionId, $groups): void
    {
        foreach ($groups as $group) {
            $games = Game::query()->where('group_id', $group->id)->get();

            foreach ($games as $game) {
                $this->assertSame($competitionId, (int) $game->competition_id);
                $this->assertSame($group->id, (int) $game->group_id);
                $this->assertSame(GameStatus::Pending, $game->status);
            }
        }
    }
}
