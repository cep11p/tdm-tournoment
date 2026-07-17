<?php

namespace Tests\Feature\Auth;

use App\Models\Group;
use Tests\TestCase;

class GroupAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
    }

    protected function tearDown(): void
    {
        $this->resetKeycloakClock();

        parent::tearDown();
    }

    public function test_groups_index_remains_public(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $this->getJson($context->apiUrl("competitions/{$competition->id}/groups"))
            ->assertOk();
    }

    public function test_create_group_requires_authentication(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $this->postJson($context->apiUrl("competitions/{$competition->id}/groups"), [
            'name' => 'Grupo A',
        ])
            ->assertUnauthorized();
    }

    public function test_create_group_forbidden_for_scorekeeper(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $context->createGroupViaApi($competition, 'Grupo A', ['scorekeeper'])
            ->assertForbidden();
    }

    public function test_organizer_can_create_group_and_assign_players(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $context->registerPlayer($competition, $player);

        $groupResponse = $context->createGroupViaApi($competition, 'Grupo A');
        $groupResponse->assertCreated();

        $groupId = $groupResponse->json('data.id');

        $context->assignPlayerToGroupViaApi(Group::query()->findOrFail($groupId), $player)
            ->assertCreated();
    }

    public function test_organizer_can_generate_random_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)
            ->assertCreated();
    }

    public function test_organizer_can_generate_round_robin(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();
    }

    public function test_organizer_with_groups_regenerate_can_regenerate_random_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $context->regenerateRandomGroups($competition, groupsCount: 2)
            ->assertCreated();
    }

    public function test_scorekeeper_cannot_regenerate_random_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $context->regenerateRandomGroups($competition, groupsCount: 2, roles: ['scorekeeper'])
            ->assertForbidden();
    }

    public function test_organizer_with_permission_receives_domain_422_for_invalid_group_structure(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 4)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['groups_count']);
    }
}
