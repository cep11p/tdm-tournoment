<?php

namespace Database\Seeders;

use App\Actions\Player\CreatePlayerAction;
use App\Models\Player;
use Database\Seeders\Support\DemoPlayerCatalog;
use Illuminate\Database\Seeder;

class DemoPlayersSeeder extends Seeder
{
    public function run(): void
    {
        $createPlayer = app(CreatePlayerAction::class);

        foreach (DemoPlayerCatalog::definitions() as $definition) {
            $existing = Player::query()
                ->where('nickname', $definition['nickname'])
                ->first();

            if ($existing !== null) {
                continue;
            }

            ($createPlayer)([
                'first_name' => $definition['first_name'],
                'last_name' => $definition['last_name'],
                'nickname' => $definition['nickname'],
            ]);
        }
    }
}
