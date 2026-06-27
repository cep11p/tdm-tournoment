<?php

namespace App\Support\Competition;

use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\Game;

final class CompetitionStatusResolver
{
    /**
     * @return array{
     *     code: string,
     *     label: string,
     *     description: string,
     *     next_action: string,
     * }
     */
    public static function resolve(Competition $competition): array
    {
        if ($competition->format->isKnockoutDirect()) {
            return self::resolveKnockoutDirect($competition);
        }

        return self::resolveGroupsKnockout($competition);
    }

    /**
     * @return array{
     *     code: string,
     *     label: string,
     *     description: string,
     *     next_action: string,
     * }
     */
    private static function resolveKnockoutDirect(Competition $competition): array
    {
        if ($competition->brackets()->exists()) {
            $completedFinal = Game::query()
                ->where('competition_id', $competition->id)
                ->whereNotNull('bracket_id')
                ->where('round', 'Final')
                ->where('status', GameStatus::Finished)
                ->whereNotNull('winner_id')
                ->exists();

            if ($completedFinal) {
                return self::summary(
                    'completed',
                    'Finalizada',
                    'La competencia ya tiene una final disputada y un ganador definido.',
                    'Ver llave',
                );
            }

            $nextAction = self::resolveKnockoutNextAction($competition);

            return self::summary(
                'knockout_in_progress',
                'Eliminatoria en curso',
                'La llave eliminatoria está generada y todavía quedan partidos por resolver.',
                $nextAction,
            );
        }

        $registeredCount = $competition->registrations()->count();

        if ($registeredCount < 2) {
            return self::summary(
                'awaiting_registrations',
                'Esperando inscriptos',
                'Se necesitan al menos 2 jugadores inscriptos para generar la llave eliminatoria.',
                'Inscribir jugadores',
            );
        }

        return self::summary(
            'ready_for_bracket',
            'Lista para generar llave',
            'Hay suficientes jugadores inscriptos y todavía no se generó la llave eliminatoria.',
            'Generar llave eliminatoria',
        );
    }

    /**
     * @return array{
     *     code: string,
     *     label: string,
     *     description: string,
     *     next_action: string,
     * }
     */
    private static function resolveGroupsKnockout(Competition $competition): array
    {
        if (! $competition->groups()->exists()) {
            return self::summary(
                'no_groups',
                'Sin grupos',
                'Todavía no hay grupos configurados para esta competencia.',
                'Gestionar grupos',
            );
        }

        $groupGamesQuery = Game::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('group_id')
            ->whereNull('bracket_id');

        if (! (clone $groupGamesQuery)->exists()) {
            return self::summary(
                'group_stage_pending',
                'Fase de grupos pendiente',
                'Hay grupos configurados, pero todavía no se generaron los partidos.',
                'Generar partidos de grupo',
            );
        }

        if ($competition->brackets()->exists()) {
            $completedFinal = Game::query()
                ->where('competition_id', $competition->id)
                ->whereNotNull('bracket_id')
                ->where('round', 'Final')
                ->where('status', GameStatus::Finished)
                ->whereNotNull('winner_id')
                ->exists();

            if ($completedFinal) {
                return self::summary(
                    'completed',
                    'Finalizada',
                    'La competencia ya tiene una final disputada y un ganador definido.',
                    'Ver llave',
                );
            }

            $nextAction = self::resolveKnockoutNextAction($competition);

            return self::summary(
                'knockout_in_progress',
                'Eliminatoria en curso',
                'La llave eliminatoria está generada y todavía quedan partidos por resolver.',
                $nextAction,
            );
        }

        $hasOpenGroupGames = (clone $groupGamesQuery)
            ->whereIn('status', [GameStatus::Pending, GameStatus::InProgress])
            ->exists();

        if ($hasOpenGroupGames) {
            return self::summary(
                'group_stage_in_progress',
                'Fase de grupos en curso',
                'Hay partidos de grupo pendientes o en curso.',
                'Completar partidos de grupos',
            );
        }

        return self::summary(
            'ready_for_bracket',
            'Lista para generar llave',
            'La fase de grupos terminó y todavía no se generó la llave eliminatoria.',
            'Generar llave eliminatoria',
        );
    }

    private static function resolveKnockoutNextAction(Competition $competition): string
    {
        $currentRound = (int) Game::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('bracket_id')
            ->max('bracket_round');

        if ($currentRound === 0) {
            return 'Ver llave';
        }

        $hasFinal = Game::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('bracket_id')
            ->where('round', 'Final')
            ->exists();

        if ($hasFinal) {
            return 'Ver llave';
        }

        $currentRoundComplete = ! Game::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('bracket_id')
            ->where('bracket_round', $currentRound)
            ->whereIn('status', [GameStatus::Pending, GameStatus::InProgress])
            ->exists();

        if ($currentRoundComplete) {
            return 'Generar siguiente ronda';
        }

        return 'Ver llave';
    }

    /**
     * @return array{
     *     code: string,
     *     label: string,
     *     description: string,
     *     next_action: string,
     * }
     */
    private static function summary(
        string $code,
        string $label,
        string $description,
        string $nextAction,
    ): array {
        return [
            'code' => $code,
            'label' => $label,
            'description' => $description,
            'next_action' => $nextAction,
        ];
    }
}
