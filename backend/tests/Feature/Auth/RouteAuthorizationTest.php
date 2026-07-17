<?php

namespace Tests\Feature\Auth;

use App\Enums\Permission;
use App\Enums\TournamentStatus;
use App\Models\Category;
use App\Models\Tournament;
use Illuminate\Support\Carbon;
use Tests\Support\KeycloakTestKeys;
use Tests\TestCase;

class RouteAuthorizationTest extends TestCase
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

    public function test_tournament_index_remains_public(): void
    {
        $this->getJson('/api/v1/tournaments')->assertOk();
    }

    public function test_create_tournament_requires_authentication(): void
    {
        $this->postJson('/api/v1/tournaments', [
            'name' => 'Torneo Protegido',
            'location' => 'Club Test',
            'start_date' => Carbon::today()->toDateString(),
            'status' => TournamentStatus::Draft->value,
        ])
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'No autenticado.',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_create_tournament_forbidden_for_player(): void
    {
        $this->postJson('/api/v1/tournaments', [
            'name' => 'Torneo Protegido',
            'location' => 'Club Test',
            'start_date' => Carbon::today()->toDateString(),
            'status' => TournamentStatus::Draft->value,
        ], $this->keycloakAuthHeaders(['player']))
            ->assertForbidden()
            ->assertJson([
                'message' => 'No autorizado.',
                'code' => 'forbidden',
            ]);
    }

    public function test_create_tournament_allowed_for_organizer(): void
    {
        $this->postJson('/api/v1/tournaments', [
            'name' => 'Torneo Protegido',
            'location' => 'Club Test',
            'start_date' => Carbon::today()->toDateString(),
            'status' => TournamentStatus::Draft->value,
        ], $this->keycloakAuthHeaders(['organizer']))
            ->assertCreated();
    }

    public function test_update_tournament_forbidden_for_scorekeeper(): void
    {
        $tournament = Tournament::query()->create([
            'name' => 'Torneo Existente',
            'location' => 'Club Test',
            'start_date' => Carbon::today()->toDateString(),
            'status' => TournamentStatus::Draft,
        ]);

        $this->putJson("/api/v1/tournaments/{$tournament->id}", [
            'name' => 'Torneo Actualizado',
        ], $this->keycloakAuthHeaders(['scorekeeper']))
            ->assertForbidden()
            ->assertJson([
                'message' => 'No autorizado.',
                'code' => 'forbidden',
            ]);
    }

    public function test_create_competition_forbidden_for_scorekeeper(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createCompetition()->tournament;

        $this->postJson($context->apiUrl("tournaments/{$tournament->id}/competitions"), [
            'name' => 'Singles Test',
            'category_id' => Category::query()->where('slug', 'primera')->value('id'),
            'type' => 'singles',
            'format' => 'groups_knockout',
            'points_per_set' => 11,
        ], $this->keycloakAuthHeaders(['scorekeeper']))
            ->assertForbidden();
    }

    public function test_scorekeeper_can_record_game_set(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $context->recordSet(
            $setup['game'],
            setNumber: 1,
            player1Score: 11,
            player2Score: 7,
            roles: ['scorekeeper'],
        )->assertOk();
    }

    public function test_record_game_set_forbidden_for_player(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $context->recordSet(
            $setup['game'],
            setNumber: 1,
            player1Score: 11,
            player2Score: 7,
            roles: ['player'],
        )->assertForbidden();
    }

    public function test_admin_receives_all_permissions_on_me(): void
    {
        $token = KeycloakTestKeys::signToken([
            'sub' => 'admin-me-subject',
            'email' => 'admin@example.com',
            'name' => 'Admin User',
            'realm_access' => ['roles' => ['admin']],
        ]);

        $response = $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $permissions = $response->json('data.permissions');

        $this->assertEqualsCanonicalizing(Permission::values(), $permissions);
    }
}
