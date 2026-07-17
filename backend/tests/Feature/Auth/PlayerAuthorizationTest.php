<?php

namespace Tests\Feature\Auth;

use App\Models\Player;
use Tests\TestCase;

class PlayerAuthorizationTest extends TestCase
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

    public function test_players_index_remains_public(): void
    {
        $this->getJson('/api/v1/players')->assertOk();
    }

    public function test_create_player_requires_authentication(): void
    {
        $this->postJson('/api/v1/players', [
            'first_name' => 'Sin',
            'last_name' => 'Token',
        ])
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'No autenticado.',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_create_player_forbidden_for_scorekeeper(): void
    {
        $this->postJson('/api/v1/players', [
            'first_name' => 'Marcador',
            'last_name' => 'Test',
        ], $this->keycloakAuthHeaders(['scorekeeper']))
            ->assertForbidden()
            ->assertJson([
                'message' => 'No autorizado.',
                'code' => 'forbidden',
            ]);
    }

    public function test_create_player_forbidden_for_player_role(): void
    {
        $this->postJson('/api/v1/players', [
            'first_name' => 'Lector',
            'last_name' => 'Test',
        ], $this->keycloakAuthHeaders(['player']))
            ->assertForbidden();
    }

    public function test_organizer_can_create_player(): void
    {
        $this->postJson('/api/v1/players', [
            'first_name' => 'Organizador',
            'last_name' => 'Crea',
        ], $this->keycloakAuthHeaders(['organizer']))
            ->assertCreated();
    }

    public function test_organizer_can_update_player(): void
    {
        $player = Player::query()->create([
            'first_name' => 'Original',
            'last_name' => 'Nombre',
        ]);

        $this->patchJson("/api/v1/players/{$player->id}", [
            'first_name' => 'Actualizado',
        ], $this->keycloakAuthHeaders(['organizer']))
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Actualizado');
    }

    public function test_organizer_can_deactivate_player(): void
    {
        $player = Player::query()->create([
            'first_name' => 'Activo',
            'last_name' => 'Jugador',
        ]);

        $this->patchJson("/api/v1/players/{$player->id}", [
            'active' => false,
        ], $this->keycloakAuthHeaders(['organizer']))
            ->assertOk()
            ->assertJsonPath('data.active', false);
    }

    public function test_organizer_can_delete_orphan_player(): void
    {
        $player = Player::query()->create([
            'first_name' => 'Huérfano',
            'last_name' => 'Jugador',
        ]);

        $this->deleteJson("/api/v1/players/{$player->id}", [], $this->keycloakAuthHeaders(['organizer']))
            ->assertNoContent();
    }
}
