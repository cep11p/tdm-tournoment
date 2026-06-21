<?php

namespace Database\Seeders;

use Database\Seeders\Support\DemoScenarioBuilder;
use Database\Seeders\Support\DemoScenarioConfig;
use Illuminate\Database\Seeder;

class DemoFourGroupsSeeder extends Seeder
{
    public function run(): void
    {
        app(DemoScenarioBuilder::class)->seed(
            new DemoScenarioConfig(
                tournamentName: 'Demo Four Groups',
                competitionName: 'Singles Four Groups',
                qualifiedPerGroup: 2,
                nicknamePrefix: 'four',
                groups: [
                    [
                        'name' => 'Grupo A',
                        'players' => [
                            'Andrés Sosa',
                            'Bruno Castillo',
                            'Cristian Vega',
                            'Damián Arias',
                        ],
                    ],
                    [
                        'name' => 'Grupo B',
                        'players' => [
                            'Esteban Núñez',
                            'Federico Molina',
                            'Gabriel Ortiz',
                            'Hernán Suárez',
                        ],
                    ],
                    [
                        'name' => 'Grupo C',
                        'players' => [
                            'Iván Rojas',
                            'Javier Medina',
                            'Kevin Duarte',
                        ],
                    ],
                    [
                        'name' => 'Grupo D',
                        'players' => [
                            'Lautaro Gómez',
                            'Mauro Benítez',
                            'Nahuel Peralta',
                        ],
                    ],
                ],
                bracketShouldSucceed: true,
                bracketNote: 'Cuartos de final (8 clasificados)',
            ),
            $this->command
        );
    }
}
