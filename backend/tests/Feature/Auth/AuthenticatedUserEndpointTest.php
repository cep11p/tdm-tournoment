<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\Support\ActsAsKeycloakUser;
use Tests\Support\KeycloakTestKeys;
use Tests\Support\KeycloakTestSupport;
use Tests\TestCase;

class AuthenticatedUserEndpointTest extends TestCase
{
    use ActsAsKeycloakUser;

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

    public function test_me_requires_bearer_token(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'No autenticado.',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_me_rejects_invalid_bearer_token(): void
    {
        $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer invalid-token',
        ])
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'No autenticado.',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_me_returns_authenticated_user_with_roles(): void
    {
        $token = KeycloakTestKeys::signToken([
            'sub' => 'me-subject-001',
            'email' => 'usuario@example.com',
            'name' => 'Usuario de prueba',
            'realm_access' => ['roles' => ['organizer', 'offline_access']],
        ]);

        $response = $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.keycloak_id', 'me-subject-001')
            ->assertJsonPath('data.name', 'Usuario de prueba')
            ->assertJsonPath('data.email', 'usuario@example.com')
            ->assertJsonPath('data.roles', ['organizer', 'offline_access']);

        $this->assertContains('tournaments.manage', $response->json('data.permissions'));

        $this->assertNotNull($response->json('data.id'));
        $this->assertArrayNotHasKey('claims', $response->json('data'));
        $this->assertArrayNotHasKey('access_token', $response->json('data'));
        $this->assertIsArray($response->json('data.permissions'));
    }

    public function test_me_creates_local_user_on_first_access(): void
    {
        $token = KeycloakTestKeys::signToken([
            'sub' => 'first-access-subject',
            'email' => 'first@example.com',
            'name' => 'Primer Acceso',
        ]);

        $this->assertDatabaseMissing('users', [
            'keycloak_id' => 'first-access-subject',
        ]);

        $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'keycloak_id' => 'first-access-subject',
            'email' => 'first@example.com',
            'name' => 'Primer Acceso',
        ]);
    }

    public function test_me_reuses_same_user_on_subsequent_access(): void
    {
        $token = KeycloakTestKeys::signToken([
            'sub' => 'reuse-subject',
            'email' => 'reuse@example.com',
            'name' => 'Nombre Inicial',
        ]);

        $first = $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $secondToken = KeycloakTestKeys::signToken([
            'sub' => 'reuse-subject',
            'email' => 'reuse@example.com',
            'name' => 'Nombre Actualizado',
        ]);

        $second = $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer '.$secondToken,
        ])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, User::query()->where('keycloak_id', 'reuse-subject')->count());
        $this->assertDatabaseHas('users', [
            'id' => $first->json('data.id'),
            'name' => 'Nombre Actualizado',
        ]);
    }

    public function test_existing_public_routes_remain_accessible_without_auth(): void
    {
        $this->getJson('/api/v1/tournaments')->assertOk();
        $this->getJson('/api/v1/categories')->assertOk();
    }
}
