<?php

namespace Tests\Feature\Auth;

use App\Support\Competition\RegistrationGuard;
use Tests\TestCase;

class RegistrationAuthorizationTest extends TestCase
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

    public function test_registrations_index_remains_public(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $this->getJson($context->apiUrl("competitions/{$competition->id}/registrations"))
            ->assertOk();
    }

    public function test_register_player_requires_authentication(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $this->postJson($context->apiUrl("competitions/{$competition->id}/registrations"), [
            'player_id' => $player->id,
        ])
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'No autenticado.',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_register_player_forbidden_for_scorekeeper(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $this->postJson($context->apiUrl("competitions/{$competition->id}/registrations"), [
            'player_id' => $player->id,
        ], $this->keycloakAuthHeaders(['scorekeeper']))
            ->assertForbidden();
    }

    public function test_register_player_forbidden_for_player_role(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $this->postJson($context->apiUrl("competitions/{$competition->id}/registrations"), [
            'player_id' => $player->id,
        ], $this->keycloakAuthHeaders(['player']))
            ->assertForbidden();
    }

    public function test_organizer_can_register_player(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competition, $player)
            ->assertCreated();
    }

    public function test_organizer_can_bulk_register_players(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $playerIds = array_map(static fn ($player) => $player->id, $players);

        $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => $playerIds],
            $this->keycloakAuthHeaders(['organizer']),
        )->assertOk();
    }

    public function test_organizer_with_permission_receives_domain_422_when_competition_locked(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        [$newPlayer] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competition, $newPlayer)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', RegistrationGuard::LOCK_MESSAGE);
    }
}
