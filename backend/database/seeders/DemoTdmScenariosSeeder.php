<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoTdmScenariosSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoSmallTournamentSeeder::class,
            DemoOnlyWinnersSeeder::class,
            DemoFourGroupsSeeder::class,
            DemoQ3FourGroupsSeeder::class,
            DemoPendingByesSeeder::class,
        ]);
    }
}
