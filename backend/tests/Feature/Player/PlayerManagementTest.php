<?php

namespace Tests\Feature\Player;

use App\Models\Player;
use App\Models\Registration;
use Tests\TestCase;

class PlayerManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_lists_players_with_search_by_name_last_name_and_nickname(): void
    {
        Player::query()->create([
            'first_name' => 'Ana',
            'last_name' => 'Garcia',
            'nickname' => 'anag',
        ]);
        Player::query()->create([
            'first_name' => 'Bruno',
            'last_name' => 'Lopez',
            'nickname' => null,
        ]);

        $this->getJson('/api/v1/players?q=Ana')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Ana');

        $this->getJson('/api/v1/players?q=Lopez')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Lopez');

        $this->getJson('/api/v1/players?q=anag')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nickname', 'anag');
    }

    public function test_lists_players_without_page_returns_flat_array_in_data(): void
    {
        Player::query()->create([
            'first_name' => 'Juan',
            'last_name' => 'Perez',
        ]);

        $response = $this->getJson('/api/v1/players');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'first_name', 'last_name', 'full_name', 'active'],
                ],
            ])
            ->assertJsonMissingPath('meta');
    }

    public function test_lists_players_with_page_returns_paginated_structure(): void
    {
        for ($index = 1; $index <= 3; $index++) {
            Player::query()->create([
                'first_name' => "Jugador{$index}",
                'last_name' => 'Test',
            ]);
        }

        $response = $this->getJson('/api/v1/players?page=1&per_page=2');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data',
                'links',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_default_list_excludes_inactive_players(): void
    {
        Player::query()->create([
            'first_name' => 'Activo',
            'last_name' => 'Uno',
            'active' => true,
        ]);
        Player::query()->create([
            'first_name' => 'Inactivo',
            'last_name' => 'Dos',
            'active' => false,
        ]);

        $response = $this->getJson('/api/v1/players');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Activo');
    }

    public function test_include_inactive_lists_inactive_players(): void
    {
        Player::query()->create([
            'first_name' => 'Activo',
            'last_name' => 'Uno',
            'active' => true,
        ]);
        Player::query()->create([
            'first_name' => 'Inactivo',
            'last_name' => 'Dos',
            'active' => false,
        ]);

        $response = $this->getJson('/api/v1/players?include_inactive=1');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_creates_valid_player(): void
    {
        $response = $this->postJson('/api/v1/players', [
            'first_name' => 'Carlos',
            'last_name' => 'Ruiz',
            'nickname' => 'caru',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.first_name', 'Carlos')
            ->assertJsonPath('data.last_name', 'Ruiz')
            ->assertJsonPath('data.nickname', 'caru')
            ->assertJsonPath('data.full_name', 'Carlos Ruiz')
            ->assertJsonPath('data.active', true);

        $this->assertDatabaseHas('players', [
            'first_name' => 'Carlos',
            'last_name' => 'Ruiz',
            'nickname' => 'caru',
            'active' => true,
        ]);
    }

    public function test_validates_required_first_name_and_last_name(): void
    {
        $response = $this->postJson('/api/v1/players', [
            'first_name' => '',
            'last_name' => '',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name']);
    }

    public function test_validates_unique_nickname(): void
    {
        Player::query()->create([
            'first_name' => 'Duplicado',
            'last_name' => 'Uno',
            'nickname' => 'mismo',
        ]);

        $response = $this->postJson('/api/v1/players', [
            'first_name' => 'Otro',
            'last_name' => 'Jugador',
            'nickname' => 'mismo',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nickname']);
    }

    public function test_updates_player(): void
    {
        $player = Player::query()->create([
            'first_name' => 'Original',
            'last_name' => 'Nombre',
            'nickname' => 'orig',
        ]);

        $response = $this->patchJson("/api/v1/players/{$player->id}", [
            'first_name' => 'Actualizado',
            'last_name' => 'Apellido',
            'nickname' => 'act',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Actualizado')
            ->assertJsonPath('data.last_name', 'Apellido')
            ->assertJsonPath('data.nickname', 'act')
            ->assertJsonPath('data.full_name', 'Actualizado Apellido');

        $this->assertDatabaseHas('players', [
            'id' => $player->id,
            'first_name' => 'Actualizado',
            'last_name' => 'Apellido',
            'nickname' => 'act',
        ]);
    }

    public function test_allows_deactivating_player(): void
    {
        $player = Player::query()->create([
            'first_name' => 'Para',
            'last_name' => 'Desactivar',
        ]);

        $response = $this->patchJson("/api/v1/players/{$player->id}", [
            'active' => false,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('players', [
            'id' => $player->id,
            'active' => false,
        ]);
    }

    public function test_rejects_registration_of_inactive_player(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $player = Player::query()->create([
            'first_name' => 'Inactivo',
            'last_name' => 'Jugador',
            'active' => false,
        ]);

        $response = $context->registerPlayerViaApi($competition, $player);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_rejects_bulk_registration_of_inactive_player(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $activePlayer = Player::query()->create([
            'first_name' => 'Activo',
            'last_name' => 'Bulk',
        ]);
        $inactivePlayer = Player::query()->create([
            'first_name' => 'Inactivo',
            'last_name' => 'Bulk',
            'active' => false,
        ]);

        $response = $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => [$activePlayer->id, $inactivePlayer->id]],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids.1']);

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_cannot_delete_player_with_relationships(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $context->registerPlayer($competition, $player);

        $response = $this->deleteJson("/api/v1/players/{$player->id}");

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player']);

        $this->assertDatabaseHas('players', ['id' => $player->id]);
    }

    public function test_can_delete_orphan_player(): void
    {
        $player = Player::query()->create([
            'first_name' => 'Huérfano',
            'last_name' => 'Jugador',
        ]);

        $response = $this->deleteJson("/api/v1/players/{$player->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('players', ['id' => $player->id]);
    }

    public function test_bulk_selector_can_consume_players_search_without_page(): void
    {
        Player::query()->create([
            'first_name' => 'Selector',
            'last_name' => 'Masivo',
            'nickname' => 'sel',
        ]);
        Player::query()->create([
            'first_name' => 'Inactivo',
            'last_name' => 'Selector',
            'active' => false,
        ]);

        $response = $this->getJson('/api/v1/players?q=Selector');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Selector')
            ->assertJsonMissingPath('meta');
    }

    public function test_show_includes_counts_without_n_plus_one(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $context->registerPlayer($competition, $player);

        $response = $this->getJson("/api/v1/players/{$player->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.registrations_count', 1)
            ->assertJsonPath('data.group_players_count', 0)
            ->assertJsonPath('data.games_count', 0);
    }
}
