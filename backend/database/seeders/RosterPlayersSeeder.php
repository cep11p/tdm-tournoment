<?php

namespace Database\Seeders;

use App\Actions\Player\CreatePlayerAction;
use App\Models\Category;
use App\Models\Player;
use Database\Seeders\Support\RosterPlayerCatalog;
use Illuminate\Database\Seeder;

class RosterPlayersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            $this->command?->warn('RosterPlayersSeeder está pensado solo para desarrollo/local/testing.');

            return;
        }

        $createPlayer = app(CreatePlayerAction::class);
        $categoryIds = Category::query()->pluck('id', 'slug');
        $created = 0;
        $reused = 0;

        foreach (RosterPlayerCatalog::definitions() as $definition) {
            $existing = Player::query()
                ->where('first_name', $definition['first_name'])
                ->where('last_name', $definition['last_name'])
                ->where(function ($query): void {
                    $query->whereNull('nickname')
                        ->orWhere('nickname', 'not like', 'demo-%');
                })
                ->first();

            if ($existing !== null) {
                $reused++;

                continue;
            }

            ($createPlayer)([
                'first_name' => $definition['first_name'],
                'last_name' => $definition['last_name'],
                'nickname' => null,
                'category_id' => $categoryIds[$definition['category']] ?? null,
            ]);
            $created++;
        }

        $this->command?->info(sprintf(
            'Integrantes: %d creados, %d reutilizados, %d en catálogo.',
            $created,
            $reused,
            count(RosterPlayerCatalog::PLAYERS),
        ));
    }
}
