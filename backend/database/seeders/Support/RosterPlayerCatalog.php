<?php

namespace Database\Seeders\Support;

final class RosterPlayerCatalog
{
    /**
     * Unique club roster recovered from FriendlyTournamentRoster.
     * Category is the highest one the player appeared in.
     *
     * @var list<array{first_name: string, last_name: string, category: string}>
     */
    public const PLAYERS = [
        ['first_name' => 'Santino', 'last_name' => 'Schepisi', 'category' => 'primera'],
        ['first_name' => 'Emiliano', 'last_name' => 'Morón', 'category' => 'primera'],
        ['first_name' => 'Juan', 'last_name' => 'Canclini', 'category' => 'primera'],
        ['first_name' => 'Daniel', 'last_name' => 'Contreras', 'category' => 'primera'],
        ['first_name' => 'Myrtha', 'last_name' => 'Menelik', 'category' => 'segunda'],
        ['first_name' => 'Mariano', 'last_name' => 'Caffre', 'category' => 'segunda'],
        ['first_name' => 'Damián', 'last_name' => 'Carrier', 'category' => 'segunda'],
        ['first_name' => 'Augusto', 'last_name' => 'Martínez', 'category' => 'segunda'],
        ['first_name' => 'Teo', 'last_name' => 'Huenulef', 'category' => 'segunda'],
        ['first_name' => 'Zamir', 'last_name' => 'Kenewi', 'category' => 'segunda'],
        ['first_name' => 'Rosario', 'last_name' => 'Ledesma', 'category' => 'segunda'],
        ['first_name' => 'Joaquín', 'last_name' => 'Rossello', 'category' => 'segunda'],
        ['first_name' => 'Luis', 'last_name' => 'Caffre', 'category' => 'segunda'],
        ['first_name' => 'Rubén', 'last_name' => 'Contreras', 'category' => 'segunda'],
        ['first_name' => 'Sabina', 'last_name' => 'Ritcher', 'category' => 'segunda'],
        ['first_name' => 'Pablo', 'last_name' => 'Porma', 'category' => 'segunda'],
        ['first_name' => 'Gustavo', 'last_name' => 'Gilardi', 'category' => 'segunda'],
        ['first_name' => 'Carlos', 'last_name' => 'Pérez', 'category' => 'segunda'],
        ['first_name' => 'Ángelo', 'last_name' => 'Pezalli', 'category' => 'segunda'],
        ['first_name' => 'Elo', 'last_name' => 'Ferrada', 'category' => 'segunda'],
        ['first_name' => 'Alejandro', 'last_name' => 'Tapia', 'category' => 'segunda'],
        ['first_name' => 'Luciano', 'last_name' => 'Lucero', 'category' => 'tercera'],
        ['first_name' => 'Rodolfo', 'last_name' => 'Sin apellido', 'category' => 'tercera'],
        ['first_name' => 'Fabricio', 'last_name' => 'Schepisi', 'category' => 'tercera'],
        ['first_name' => 'Feliciano', 'last_name' => 'Gilardi', 'category' => 'tercera'],
        ['first_name' => 'Almendra', 'last_name' => 'Porma', 'category' => 'tercera'],
        ['first_name' => 'Aurelio', 'last_name' => 'Martínez', 'category' => 'tercera'],
        ['first_name' => 'Daniel', 'last_name' => 'Leibof', 'category' => 'tercera'],
        ['first_name' => 'Rebeca', 'last_name' => 'Hantis', 'category' => 'tercera'],
        ['first_name' => 'Eduardo', 'last_name' => 'Crest', 'category' => 'tercera'],
        ['first_name' => 'Valentín', 'last_name' => 'Forno', 'category' => 'cuarta'],
        ['first_name' => 'Francesca', 'last_name' => 'Escale', 'category' => 'cuarta'],
        ['first_name' => 'Melanie', 'last_name' => 'Soberon', 'category' => 'cuarta'],
        ['first_name' => 'Emilce', 'last_name' => 'Soberon', 'category' => 'cuarta'],
        ['first_name' => 'Naila', 'last_name' => 'Torres', 'category' => 'cuarta'],
        ['first_name' => 'Clovis', 'last_name' => 'Contreras', 'category' => 'cuarta'],
    ];

    /**
     * @return list<array{first_name: string, last_name: string, category: string}>
     */
    public static function definitions(): array
    {
        return self::PLAYERS;
    }
}
