<?php

namespace Tests\Feature\Player;

use App\Models\Category;
use App\Models\Club;
use App\Models\Player;
use Tests\TestCase;

class PlayerCategoryClubTest extends TestCase
{
    public function test_creates_player_with_category_and_club(): void
    {
        $category = Category::query()->where('slug', 'primera')->firstOrFail();
        $club = Club::query()->create(['name' => 'Club Norte']);

        $response = $this->postJson('/api/v1/players', [
            'first_name' => 'Ana',
            'last_name' => 'Lopez',
            'category_id' => $category->id,
            'club_id' => $club->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.category.slug', 'primera')
            ->assertJsonPath('data.club.id', $club->id)
            ->assertJsonPath('data.club.name', 'Club Norte');

        $this->assertDatabaseHas('players', [
            'first_name' => 'Ana',
            'category_id' => $category->id,
            'club_id' => $club->id,
        ]);
    }

    public function test_creates_player_without_category_and_club(): void
    {
        $response = $this->postJson('/api/v1/players', [
            'first_name' => 'Sin',
            'last_name' => 'Datos',
            'category_id' => null,
            'club_id' => null,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.category_id', null)
            ->assertJsonPath('data.club_id', null);

        $this->assertDatabaseHas('players', [
            'first_name' => 'Sin',
            'category_id' => null,
            'club_id' => null,
        ]);
    }

    public function test_rejects_invalid_category_id(): void
    {
        $response = $this->postJson('/api/v1/players', [
            'first_name' => 'Invalida',
            'last_name' => 'Categoria',
            'category_id' => 999999,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_rejects_invalid_club_id(): void
    {
        $response = $this->postJson('/api/v1/players', [
            'first_name' => 'Invalido',
            'last_name' => 'Club',
            'club_id' => 999999,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['club_id']);
    }

    public function test_filters_players_by_category_id(): void
    {
        $primera = Category::query()->where('slug', 'primera')->firstOrFail();
        $segunda = Category::query()->where('slug', 'segunda')->firstOrFail();

        Player::query()->create([
            'first_name' => 'Primera',
            'last_name' => 'Uno',
            'category_id' => $primera->id,
        ]);
        Player::query()->create([
            'first_name' => 'Segunda',
            'last_name' => 'Dos',
            'category_id' => $segunda->id,
        ]);

        $response = $this->getJson("/api/v1/players?category_id={$primera->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Primera');
    }

    public function test_filters_players_by_club_id(): void
    {
        $clubA = Club::query()->create(['name' => 'Club A']);
        $clubB = Club::query()->create(['name' => 'Club B']);

        Player::query()->create([
            'first_name' => 'Del',
            'last_name' => 'ClubA',
            'club_id' => $clubA->id,
        ]);
        Player::query()->create([
            'first_name' => 'Del',
            'last_name' => 'ClubB',
            'club_id' => $clubB->id,
        ]);

        $response = $this->getJson("/api/v1/players?club_id={$clubA->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'ClubA');
    }

    public function test_combines_search_category_club_and_active_filters(): void
    {
        $category = Category::query()->where('slug', 'tercera')->firstOrFail();
        $club = Club::query()->create(['name' => 'Club Combinado']);

        Player::query()->create([
            'first_name' => 'Target',
            'last_name' => 'Activo',
            'nickname' => 'tgt',
            'category_id' => $category->id,
            'club_id' => $club->id,
            'active' => true,
        ]);
        Player::query()->create([
            'first_name' => 'Target',
            'last_name' => 'Inactivo',
            'category_id' => $category->id,
            'club_id' => $club->id,
            'active' => false,
        ]);
        Player::query()->create([
            'first_name' => 'Target',
            'last_name' => 'OtroClub',
            'category_id' => $category->id,
            'active' => true,
        ]);

        $response = $this->getJson(
            "/api/v1/players?q=Target&category_id={$category->id}&club_id={$club->id}",
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Activo');

        $responseWithInactive = $this->getJson(
            "/api/v1/players?q=Target&category_id={$category->id}&club_id={$club->id}&include_inactive=1",
        );

        $responseWithInactive
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_show_resource_includes_category_and_club(): void
    {
        $category = Category::query()->where('slug', 'libre')->firstOrFail();
        $club = Club::query()->create(['name' => 'Club Show']);

        $player = Player::query()->create([
            'first_name' => 'Show',
            'last_name' => 'Resource',
            'category_id' => $category->id,
            'club_id' => $club->id,
        ]);

        $response = $this->getJson("/api/v1/players/{$player->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.category.name', 'Libre')
            ->assertJsonPath('data.club.name', 'Club Show');
    }
}
