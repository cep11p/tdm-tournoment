<?php

/**
 * Ejemplos de uso del sistema — MVP TDM Tournament
 *
 * Este archivo es solo ilustrativo, no es código ejecutable directo.
 * Refleja cómo se usa cada parte del sistema desde un Controller o Seeder.
 */

use App\Models\Competition;
use App\Models\Match;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Tournament;
use App\Services\MatchService;

// -------------------------------------------------------------------------
// 1. Crear un torneo
// -------------------------------------------------------------------------

$tournament = Tournament::create([
    'name'       => 'Torneo Apertura 2026',
    'location'   => 'Club Viedma',
    'start_date' => '2026-05-10',
    'end_date'   => '2026-05-11',
    'status'     => 'draft',
]);

// -------------------------------------------------------------------------
// 2. Crear una competencia dentro del torneo
// -------------------------------------------------------------------------

$competition = Competition::create([
    'tournament_id'  => $tournament->id,
    'name'           => 'Singles Primera',
    'type'           => 'singles',
    'category'       => 'primera',
    'format'         => 'manual',
    'sets_to_win'    => 3,
    'points_per_set' => 11,
]);

// -------------------------------------------------------------------------
// 3. Registrar jugadores
// -------------------------------------------------------------------------

$carlos = Player::create([
    'first_name' => 'Carlos',
    'last_name'  => 'Pérez',
    'nickname'   => 'Carlitos',
]);

$juan = Player::create([
    'first_name' => 'Juan',
    'last_name'  => 'López',
]);

// -------------------------------------------------------------------------
// 4. Inscribir jugadores a la competencia
// -------------------------------------------------------------------------

Registration::create([
    'competition_id' => $competition->id,
    'player_id'      => $carlos->id,
]);

Registration::create([
    'competition_id' => $competition->id,
    'player_id'      => $juan->id,
]);

// Si se intenta inscribir dos veces, la restricción UNIQUE lanzará una excepción:
// Registration::create(['competition_id' => $competition->id, 'player_id' => $carlos->id]);
// → Illuminate\Database\QueryException: UNIQUE constraint failed

// -------------------------------------------------------------------------
// 5. Crear un partido manualmente
// -------------------------------------------------------------------------

$match = Match::create([
    'competition_id' => $competition->id,
    'player1_id'     => $carlos->id,
    'player2_id'     => $juan->id,
    'status'         => 'pending',
    'round'          => null,
]);

// -------------------------------------------------------------------------
// 6. Cargar sets y calcular el ganador automáticamente
// -------------------------------------------------------------------------

$service = app(MatchService::class);

// Set 1: Carlos 11 - Juan 8 → gana Carlos
$service->addSet($match, setNumber: 1, player1Score: 11, player2Score: 8);

// Set 2: Carlos 9 - Juan 11 → gana Juan
$service->addSet($match, setNumber: 2, player1Score: 9, player2Score: 11);

// Set 3: Carlos 11 - Juan 6 → gana Carlos
$service->addSet($match, setNumber: 3, player1Score: 11, player2Score: 6);

// Después del set 3: Carlos tiene 2 sets, Juan tiene 1.
// Aún no hay ganador (sets_to_win = 3).

// Set 4: Carlos 11 - Juan 9 → gana Carlos
$service->addSet($match, setNumber: 4, player1Score: 11, player2Score: 9);

// Ahora Carlos tiene 3 sets ganados → alcanza sets_to_win.
// El sistema asigna automáticamente:
//   winner_id = $carlos->id
//   status    = 'finished'

$match->refresh();

echo $match->status;    // finished
echo $match->winner_id; // id de Carlos

// -------------------------------------------------------------------------
// 7. Verificar el ganador
// -------------------------------------------------------------------------

$match->load('winner', 'sets');

echo $match->winner->full_name; // "Carlos Pérez"

foreach ($match->sets as $set) {
    echo "Set {$set->set_number}: {$set->player1_score} - {$set->player2_score}";
}

// -------------------------------------------------------------------------
// 8. Acceso a relaciones
// -------------------------------------------------------------------------

// Todas las competencias de un torneo:
$tournament->competitions;

// Todos los partidos de una competencia:
$competition->matches;

// Jugadores inscriptos en una competencia:
$competition->registrations->pluck('player');

// Sets de un partido ordenados:
$match->sets; // orden por set_number definido en el modelo
