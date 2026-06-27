<?php

namespace Database\Seeders;

use Database\Seeders\Support\CleanTechnicalPlayerNicknames;
use Database\Seeders\Support\FriendlyTournamentRoster;
use Database\Seeders\Support\MisSeedsDePruebaBuilder;
use Illuminate\Database\Seeder;

class MisSeedsDePruebaSeeder extends Seeder
{
    private const TOURNAMENT_SOLO_INSCRIPTOS = 'Mis Pruebas - Solo Inscriptos';

    private const TOURNAMENT_CON_GRUPOS = 'Mis Pruebas - Con Grupos';

    private const TOURNAMENT_ROUND_ROBIN_PENDIENTE = 'Mis Pruebas - Round Robin Pendiente';

    private const TOURNAMENT_RESULTADOS_PARCIALES = 'Mis Pruebas - Resultados Parciales';

    private const TOURNAMENT_ELIMINACION_DIRECTA = 'Mis Pruebas - Eliminación Directa';

    public function run(): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            $this->command?->warn('MisSeedsDePruebaSeeder está pensado solo para desarrollo/local/testing.');

            return;
        }

        $cleanedNicknames = CleanTechnicalPlayerNicknames::cleanAmistosoPrefix();
        $this->command?->line(sprintf('Nicknames amistoso-* limpiados: %d', $cleanedNicknames));

        $builder = new MisSeedsDePruebaBuilder($this->command);

        $this->seedSoloInscriptos($builder);
        $this->seedConGrupos($builder);
        $this->seedRoundRobinPendiente($builder);
        $this->seedResultadosParciales($builder);
        $this->seedEliminacionDirecta($builder);

        $this->command?->newLine();
        $this->command?->info('MisSeedsDePruebaSeeder finalizado.');
    }

    private function seedSoloInscriptos(MisSeedsDePruebaBuilder $builder): void
    {
        $base = $builder->seedBaseTournament(self::TOURNAMENT_SOLO_INSCRIPTOS);

        $builder->printScenarioSummary(self::TOURNAMENT_SOLO_INSCRIPTOS, [
            'Torneo' => $base['tournament_created'] ? 'creado' : 'reutilizado',
            'Competencias creadas' => $base['competitions_created'],
            'Competencias reutilizadas' => $base['competitions_reused'],
            'Inscripciones nuevas' => $base['registrations_created'],
            'Inscripciones reutilizadas' => $base['registrations_reused'],
            'Grupos' => 0,
            'Partidos' => 0,
            'Resultados cargados' => 0,
        ]);
    }

    private function seedConGrupos(MisSeedsDePruebaBuilder $builder): void
    {
        $base = $builder->seedBaseTournament(self::TOURNAMENT_CON_GRUPOS);
        $groups = $builder->assignAllDeterministicGroups($base['competitions']);

        $builder->printScenarioSummary(self::TOURNAMENT_CON_GRUPOS, [
            'Torneo' => $base['tournament_created'] ? 'creado' : 'reutilizado',
            'Competencias creadas' => $base['competitions_created'],
            'Competencias reutilizadas' => $base['competitions_reused'],
            'Inscripciones nuevas' => $base['registrations_created'],
            'Inscripciones reutilizadas' => $base['registrations_reused'],
            'Grupos creados' => $groups['groups_created'],
            'Asignaciones nuevas' => $groups['assignments_created'],
            'Partidos' => 0,
            'Resultados cargados' => 0,
        ]);
    }

    private function seedRoundRobinPendiente(MisSeedsDePruebaBuilder $builder): void
    {
        $base = $builder->seedBaseTournament(self::TOURNAMENT_ROUND_ROBIN_PENDIENTE);
        $groups = $builder->assignAllDeterministicGroups($base['competitions']);
        $gamesGenerated = $builder->generateAllRoundRobinGames($base['competitions']);

        $builder->printScenarioSummary(self::TOURNAMENT_ROUND_ROBIN_PENDIENTE, [
            'Torneo' => $base['tournament_created'] ? 'creado' : 'reutilizado',
            'Competencias creadas' => $base['competitions_created'],
            'Competencias reutilizadas' => $base['competitions_reused'],
            'Inscripciones nuevas' => $base['registrations_created'],
            'Inscripciones reutilizadas' => $base['registrations_reused'],
            'Grupos creados' => $groups['groups_created'],
            'Asignaciones nuevas' => $groups['assignments_created'],
            'Partidos generados' => $gamesGenerated,
            'Resultados cargados' => 0,
        ]);
    }

    private function seedResultadosParciales(MisSeedsDePruebaBuilder $builder): void
    {
        $base = $builder->seedBaseTournament(self::TOURNAMENT_RESULTADOS_PARCIALES);
        $groups = $builder->assignAllDeterministicGroups($base['competitions']);
        $gamesGenerated = $builder->generateAllRoundRobinGames($base['competitions']);
        $gamesFinished = $builder->finishPartialResults($base['competitions']);

        $builder->printScenarioSummary(self::TOURNAMENT_RESULTADOS_PARCIALES, [
            'Torneo' => $base['tournament_created'] ? 'creado' : 'reutilizado',
            'Competencias creadas' => $base['competitions_created'],
            'Competencias reutilizadas' => $base['competitions_reused'],
            'Inscripciones nuevas' => $base['registrations_created'],
            'Inscripciones reutilizadas' => $base['registrations_reused'],
            'Grupos creados' => $groups['groups_created'],
            'Asignaciones nuevas' => $groups['assignments_created'],
            'Partidos generados' => $gamesGenerated,
            'Resultados cargados' => $gamesFinished,
        ]);
    }

    private function seedEliminacionDirecta(MisSeedsDePruebaBuilder $builder): void
    {
        $base = $builder->seedKnockoutDirectTournament(
            self::TOURNAMENT_ELIMINACION_DIRECTA,
            [
                [
                    'category' => 'primera',
                    'name' => 'Singles - Primera Directa',
                ],
                [
                    'category' => 'cuarta',
                    'name' => 'Singles - Cuarta Directa',
                ],
                [
                    'category' => 'segunda',
                    'name' => 'Singles - Segunda Directa',
                    'player_names' => array_slice(
                        FriendlyTournamentRoster::PLAYERS_BY_CATEGORY['segunda'],
                        0,
                        8,
                    ),
                ],
            ],
        );

        $builder->printScenarioSummary(self::TOURNAMENT_ELIMINACION_DIRECTA, [
            'Torneo' => $base['tournament_created'] ? 'creado' : 'reutilizado',
            'Competencias creadas' => $base['competitions_created'],
            'Competencias reutilizadas' => $base['competitions_reused'],
            'Inscripciones nuevas' => $base['registrations_created'],
            'Inscripciones reutilizadas' => $base['registrations_reused'],
            'Formato' => 'knockout_direct',
            'Grupos' => 0,
            'Partidos' => 0,
        ]);
    }
}
