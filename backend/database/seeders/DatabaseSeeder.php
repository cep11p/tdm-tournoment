<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            TeamTieFormatSeeder::class,
        ]);

        if ($this->shouldSeedDemo()) {
            $this->call([
                DemoPlayersSeeder::class,
                DemoTournamentSeeder::class,
                DemoArchivedTournamentSeeder::class,
            ]);
        }
    }

    private function shouldSeedDemo(): bool
    {
        if (! app()->environment(['local', 'development'])) {
            return false;
        }

        return (bool) config('demo.seed_data', false);
    }
}
