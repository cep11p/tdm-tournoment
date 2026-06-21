<?php

namespace Database\Seeders;

use Database\Seeders\Support\DemoScenarioBuilder;
use Database\Seeders\Support\DemoScenarioConfig;
use Illuminate\Database\Seeder;

class DemoPendingByesSeeder extends Seeder
{
    public function run(): void
    {
        app(DemoScenarioBuilder::class)->seed(
            new DemoScenarioConfig(
                tournamentName: 'Demo Pending BYEs',
                competitionName: 'Singles Club',
                qualifiedPerGroup: 3,
                nicknamePrefix: 'byes',
                groups: [
                    [
                        'name' => 'Grupo A',
                        'players' => ['Club A1', 'Club A2', 'Club A3', 'Club A4', 'Club A5'],
                    ],
                    [
                        'name' => 'Grupo B',
                        'players' => ['Club B1', 'Club B2', 'Club B3', 'Club B4'],
                    ],
                    [
                        'name' => 'Grupo C',
                        'players' => ['Club C1', 'Club C2', 'Club C3', 'Club C4'],
                    ],
                    [
                        'name' => 'Grupo D',
                        'players' => ['Club D1', 'Club D2', 'Club D3'],
                    ],
                    [
                        'name' => 'Grupo E',
                        'players' => ['Club E1', 'Club E2', 'Club E3'],
                    ],
                    [
                        'name' => 'Grupo F',
                        'players' => ['Club F1', 'Club F2', 'Club F3'],
                    ],
                    [
                        'name' => 'Grupo G',
                        'players' => ['Club G1', 'Club G2', 'Club G3'],
                    ],
                    [
                        'name' => 'Grupo H',
                        'players' => ['Club H1', 'Club H2', 'Club H3'],
                    ],
                    [
                        'name' => 'Grupo I',
                        'players' => ['Club I1', 'Club I2', 'Club I3'],
                    ],
                    [
                        'name' => 'Grupo J',
                        'players' => ['Club J1', 'Club J2', 'Club J3'],
                    ],
                ],
                bracketShouldSucceed: false,
                bracketNote: '30 clasificados → falla hoy; futuro: bracket de 32 con 2 BYEs',
            ),
            $this->command
        );
    }
}
