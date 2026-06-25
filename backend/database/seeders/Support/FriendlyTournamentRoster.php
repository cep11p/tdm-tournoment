<?php

namespace Database\Seeders\Support;

final class FriendlyTournamentRoster
{
    /**
     * @var array<string, array<int, string>>
     */
    public const PLAYERS_BY_CATEGORY = [
        'primera' => [
            'Santino Schepisi',
            'Emiliano Morón',
            'Juan Canclini',
            'Daniel Contreras',
        ],

        'segunda' => [
            'Myrtha Menelik',
            'Santino Schepisi',
            'Mariano Caffre',
            'Damián Carrier',
            'Emiliano Morón',
            'Augusto Martínez',
            'Teo Huenulef',
            'Zamir Kenewi',
            'Juan Canclini',
            'Rosario Ledesma',
            'Joaquín Rossello',
            'Luis Cafre',
            'Rubén Contreras',
            'Sabina Ritcher',
            'Pablo Porma',
            'Gustavo Gilardi',
            'Carlos Pérez',
            'Ángelo Pezalli',
            'Elo Ferrada',
            'Alejandro Tapia',
        ],

        'tercera' => [
            'Myrtha Menelik',
            'Luciano Lucero',
            'Rodolfo',
            'Fabricio Schepisi',
            'Mariano Caffre',
            'Damián Carrier',
            'Augusto Martínez',
            'Feliciano Gilardi',
            'Teo Huenulef',
            'Zamir Kenewi',
            'Rosario Ledesma',
            'Almendra Porma',
            'Joaquín Rosello',
            'Luis Caffre',
            'Rubén Contreras',
            'Sabina Ritcher',
            'Pablo Porma',
            'Gustavo Gilardi',
            'Carlos Pérez',
            'Ángelo Pezalli',
            'Elof Ferrada',
            'Aurelio Martínez',
            'Daniel Leibof',
            'Alejandro Tapia',
            'Rebeca Hantis',
            'Eduardo Crest',
        ],

        'cuarta' => [
            'Valentín Forno',
            'Francesca Escale',
            'Melanie Soberon',
            'Emilce Soberon',
            'Naila Torres',
            'Luciano Lucero',
            'Rodolfo',
            'Clovis Contreras',
            'Feliciano Gilardi',
            'Almendra Porma',
            'Aurelio Martínez',
            'Daniel Leibof',
            'Rebeca Hantis',
            'Eduardo Crest',
        ],
    ];

    /**
     * @var array<string, string>
     */
    public const COMPETITION_NAMES = [
        'primera' => 'Singles - Primera',
        'segunda' => 'Singles - Segunda',
        'tercera' => 'Singles - Tercera',
        'cuarta' => 'Singles - Cuarta',
    ];
}
