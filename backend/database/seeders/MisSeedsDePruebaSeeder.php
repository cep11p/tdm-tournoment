<?php

namespace Database\Seeders;

use Database\Seeders\Support\MisSeedsDePruebaBuilder;
use Illuminate\Database\Seeder;

class MisSeedsDePruebaSeeder extends Seeder
{
    private const TOURNAMENT_SOLO_INSCRIPTOS = 'Mis Pruebas - Solo Inscriptos';

    private const TOURNAMENT_CON_GRUPOS = 'Mis Pruebas - Con Grupos';

    private const TOURNAMENT_ROUND_ROBIN_PENDIENTE = 'Mis Pruebas - Round Robin Pendiente';

    private const TOURNAMENT_RESULTADOS_PARCIALES = 'Mis Pruebas - Resultados Parciales';

    public function run(): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            $this->command?->warn('MisSeedsDePruebaSeeder está pensado solo para desarrollo/local/testing.');

            return;
        }

        $builder = new MisSeedsDePruebaBuilder($this->command);

        $this->seedSoloInscriptos($builder);
        $this->seedConGrupos($builder);
        $this->seedRoundRobinPendiente($builder);
        $this->seedResultadosParciales($builder);

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
}
