<?php

namespace Tests\Feature\Seed;

use App\Models\Category;
use App\Models\Player;
use Database\Seeders\DemoPlayersSeeder;
use Database\Seeders\RosterPlayersSeeder;
use Database\Seeders\Support\RosterPlayerCatalog;
use Tests\TestCase;

class RosterPlayersSeederTest extends TestCase
{
    public function test_creates_unique_roster_players_with_highest_category(): void
    {
        $this->seed(RosterPlayersSeeder::class);

        $definitions = RosterPlayerCatalog::definitions();
        $categoryIds = Category::query()->pluck('id', 'slug');

        $this->assertCount(36, $definitions);
        $this->assertSame(36, Player::query()->count());
        $this->assertDatabaseHas('players', [
            'first_name' => 'Emiliano',
            'last_name' => 'Morón',
            'category_id' => $categoryIds['primera'],
        ]);

        foreach ($definitions as $definition) {
            $this->assertDatabaseHas('players', [
                'first_name' => $definition['first_name'],
                'last_name' => $definition['last_name'],
                'category_id' => $categoryIds[$definition['category']],
            ]);
        }
    }

    public function test_is_idempotent(): void
    {
        $this->seed(RosterPlayersSeeder::class);
        $this->seed(RosterPlayersSeeder::class);

        $this->assertSame(36, Player::query()->count());
    }

    public function test_does_not_reuse_demo_players(): void
    {
        $this->seed(DemoPlayersSeeder::class);
        $this->seed(RosterPlayersSeeder::class);

        $this->assertSame(16 + 36, Player::query()->count());
        $this->assertDatabaseHas('players', [
            'first_name' => 'Carlos',
            'last_name' => 'Perez',
            'nickname' => 'demo-carlos-perez',
        ]);
        $this->assertDatabaseHas('players', [
            'first_name' => 'Carlos',
            'last_name' => 'Pérez',
            'nickname' => null,
        ]);
    }
}
