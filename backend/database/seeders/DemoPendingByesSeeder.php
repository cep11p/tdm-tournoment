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
                        'players' => [
                            'Oscar Ruiz',
                            'Patricio Campos',
                            'Ramón Delgado',
                            'Rubén Espinoza',
                            'Sebastián Fuentes',
                        ],
                    ],
                    [
                        'name' => 'Grupo B',
                        'players' => [
                            'Ulises Galván',
                            'Valentín Huerta',
                            'Walter Ibáñez',
                            'Álvaro Jiménez',
                        ],
                    ],
                    [
                        'name' => 'Grupo C',
                        'players' => [
                            'Benjamín Lara',
                            'César Mendoza',
                            'Darío Ochoa',
                            'Eduardo Paredes',
                        ],
                    ],
                    [
                        'name' => 'Grupo D',
                        'players' => [
                            'Fabián Quiroga',
                            'Gonzalo Reyna',
                            'Hugo Salazar',
                        ],
                    ],
                    [
                        'name' => 'Grupo E',
                        'players' => [
                            'Ignacio Tamayo',
                            'Julio Ubilla',
                            'Leonel Valdés',
                        ],
                    ],
                    [
                        'name' => 'Grupo F',
                        'players' => [
                            'Manuel Velasco',
                            'Norberto Yáñez',
                            'Octavio Zárate',
                        ],
                    ],
                    [
                        'name' => 'Grupo G',
                        'players' => [
                            'Ricardo Abreu',
                            'Salvador Bracco',
                            'Tristán Cáceres',
                        ],
                    ],
                    [
                        'name' => 'Grupo H',
                        'players' => [
                            'Uriel Domínguez',
                            'Vicente Errázuriz',
                            'Wenceslao Farías',
                        ],
                    ],
                    [
                        'name' => 'Grupo I',
                        'players' => [
                            'Adrián Giménez',
                            'Bernardo Holler',
                            'Carlos Ibarra',
                        ],
                    ],
                    [
                        'name' => 'Grupo J',
                        'players' => [
                            'Danilo Jara',
                            'Enzo Keller',
                            'Felipe Latorre',
                        ],
                    ],
                ],
                bracketShouldSucceed: true,
                bracketNote: '30 clasificados → bracket de 32 con 2 BYEs',
            ),
            $this->command
        );
    }
}
