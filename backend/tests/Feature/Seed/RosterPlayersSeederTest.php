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

        $this->assertCount(44, $definitions);
        $this->assertSame(44, Player::query()->count());
        $this->assertDatabaseHas('players', [
            'first_name' => 'Emiliano',
            'last_name' => 'Morón',
            'category_id' => $categoryIds['primera'],
        ]);

        $identityKeys = collect($definitions)
            ->map(fn (array $definition): string => $definition['first_name'].'|'.$definition['last_name']);
        $this->assertSame($identityKeys->count(), $identityKeys->unique()->count());

        foreach ($definitions as $definition) {
            $this->assertDatabaseHas('players', [
                'first_name' => $definition['first_name'],
                'last_name' => $definition['last_name'],
                'category_id' => $definition['category'] === null
                    ? null
                    : $categoryIds[$definition['category']],
            ]);
        }

        $this->assertSame(1, Player::query()->where('first_name', 'Jonathan')->count());
        $this->assertDatabaseHas('players', [
            'first_name' => 'Jonathan',
            'last_name' => '',
            'nickname' => null,
            'category_id' => null,
        ]);
    }

    public function test_is_idempotent(): void
    {
        $this->seed(RosterPlayersSeeder::class);
        $this->seed(RosterPlayersSeeder::class);

        $this->assertSame(44, Player::query()->count());
    }

    public function test_does_not_reuse_demo_players(): void
    {
        $this->seed(DemoPlayersSeeder::class);
        $this->seed(RosterPlayersSeeder::class);

        $this->assertSame(16 + 44, Player::query()->count());
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
