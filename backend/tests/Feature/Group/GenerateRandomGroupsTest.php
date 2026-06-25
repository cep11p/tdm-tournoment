<?php

namespace Tests\Feature\Group;

use App\Models\GroupPlayer;
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

    public function test_does_not_create_games(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $this->assertDatabaseCount('games', 0);
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
}
