<?php

namespace App\Support\Competition;

use App\Enums\CompetitionType;
use App\Enums\GameStatus;
use App\Enums\TeamTieStatus;
use App\Enums\ThirdPlaceMode;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\Game;
use App\Models\TeamTie;
use App\Support\Bracket\BracketPodiumSupport;
use App\Support\Bracket\GroupBracketReadiness;

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
            if (self::isKnockoutCompleted($competition)) {
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

        $registeredCount = $competition->entries()->count();

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

        $isTeamCompetition = self::isTeamCompetition($competition);

        if ($isTeamCompetition) {
            $groupScheduleExists = TeamTie::query()
                ->where('competition_id', $competition->id)
                ->whereNotNull('group_id')
                ->exists();

            if (! $groupScheduleExists) {
                return self::summary(
                    'group_stage_pending',
                    'Fase de grupos pendiente',
                    'Hay grupos configurados, pero todavía no se generaron los enfrentamientos.',
                    'Generar enfrentamientos de grupo',
                );
            }
        } else {
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
        }

        if ($competition->brackets()->exists()) {
            if (self::isKnockoutCompleted($competition)) {
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

        if ($isTeamCompetition) {
            $hasOpenGroupSchedule = TeamTie::query()
                ->where('competition_id', $competition->id)
                ->whereNotNull('group_id')
                ->whereIn('status', [TeamTieStatus::Pending, TeamTieStatus::InProgress])
                ->exists();
        } else {
            $groupGamesQuery = Game::query()
                ->where('competition_id', $competition->id)
                ->whereNotNull('group_id')
                ->whereNull('bracket_id');

            $hasOpenGroupSchedule = (clone $groupGamesQuery)
                ->whereIn('status', [GameStatus::Pending, GameStatus::InProgress])
                ->exists();
        }

        if ($hasOpenGroupSchedule) {
            return self::summary(
                'group_stage_in_progress',
                'Fase de grupos en curso',
                $isTeamCompetition
                    ? 'Hay enfrentamientos de grupo pendientes o en curso.'
                    : 'Hay partidos de grupo pendientes o en curso.',
                $isTeamCompetition
                    ? 'Completar enfrentamientos de grupos'
                    : 'Completar partidos de grupos',
            );
        }

        if (app(GroupBracketReadiness::class)->requiresAttentionBeforeBracket($competition)) {
            return self::summary(
                'group_stage_attention_required',
                'Fase de grupos requiere atención',
                'Hay desempates manuales pendientes o desactualizados que deben resolverse antes de generar la llave.',
                'Resolver desempates de grupos',
            );
        }

        return self::summary(
            'ready_for_bracket',
            'Lista para generar llave',
            'La fase de grupos terminó y todavía no se generó la llave eliminatoria.',
            'Generar llave eliminatoria',
        );
    }

    private static function isKnockoutCompleted(Competition $competition): bool
    {
        if (self::isTeamCompetition($competition)) {
            return self::isTeamKnockoutCompleted($competition);
        }

        return self::isGameKnockoutCompleted($competition);
    }

    private static function isGameKnockoutCompleted(Competition $competition): bool
    {
        $finalFinished = Game::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('bracket_id')
            ->mainBracket()
            ->where('round', 'Final')
            ->where('status', GameStatus::Finished)
            ->whereNotNull('winner_entry_id')
            ->exists();

        if (! $finalFinished) {
            return false;
        }

        return self::isPlayoffThirdPlaceCompleted($competition, fn (Bracket $bracket) => BracketPodiumSupport::findThirdPlaceGame($bracket));
    }

    private static function isTeamKnockoutCompleted(Competition $competition): bool
    {
        $finalFinished = TeamTie::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('bracket_id')
            ->mainBracket()
            ->where('round', 'Final')
            ->where('status', TeamTieStatus::Finished)
            ->whereNotNull('winner_entry_id')
            ->exists();

        if (! $finalFinished) {
            return false;
        }

        return self::isPlayoffThirdPlaceCompleted($competition, fn (Bracket $bracket) => BracketPodiumSupport::findThirdPlaceTeamTie($bracket));
    }

    /**
     * @param  callable(Bracket): (Game|TeamTie|null)  $findThirdPlace
     */
    private static function isPlayoffThirdPlaceCompleted(Competition $competition, callable $findThirdPlace): bool
    {
        $thirdPlaceMode = $competition->third_place_mode instanceof ThirdPlaceMode
            ? $competition->third_place_mode
            : ThirdPlaceMode::from((string) $competition->third_place_mode);

        if ($thirdPlaceMode !== ThirdPlaceMode::Playoff) {
            return true;
        }

        $bracket = $competition->brackets()->first();

        if ($bracket === null || ! BracketPodiumSupport::requiresPlayoffThirdPlace($competition, $bracket)) {
            return true;
        }

        $thirdPlaceMatch = $findThirdPlace($bracket);

        if ($thirdPlaceMatch === null) {
            return false;
        }

        if ($thirdPlaceMatch instanceof Game) {
            return $thirdPlaceMatch->status === GameStatus::Finished
                && $thirdPlaceMatch->winner_entry_id !== null;
        }

        return $thirdPlaceMatch->status === TeamTieStatus::Finished
            && $thirdPlaceMatch->winner_entry_id !== null;
    }

    private static function resolveKnockoutNextAction(Competition $competition): string
    {
        if (self::isTeamCompetition($competition)) {
            return self::resolveTeamKnockoutNextAction($competition);
        }

        return self::resolveGameKnockoutNextAction($competition);
    }

    private static function resolveGameKnockoutNextAction(Competition $competition): string
    {
        $currentRound = (int) Game::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('bracket_id')
            ->mainBracket()
            ->max('bracket_round');

        if ($currentRound === 0) {
            return 'Ver llave';
        }

        $finalGame = Game::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('bracket_id')
            ->mainBracket()
            ->where('round', 'Final')
            ->first();

        $thirdPlaceMode = $competition->third_place_mode instanceof ThirdPlaceMode
            ? $competition->third_place_mode
            : ThirdPlaceMode::from((string) $competition->third_place_mode);

        $bracket = $competition->brackets()->first();
        $requiresThirdPlace = $bracket !== null
            && $thirdPlaceMode === ThirdPlaceMode::Playoff
            && BracketPodiumSupport::requiresPlayoffThirdPlace($competition, $bracket);

        if ($requiresThirdPlace) {
            $thirdPlaceGame = BracketPodiumSupport::findThirdPlaceGame($bracket);
            $finalFinished = $finalGame !== null
                && $finalGame->status === GameStatus::Finished
                && $finalGame->winner_entry_id !== null;
            $thirdPlaceFinished = $thirdPlaceGame !== null
                && $thirdPlaceGame->status === GameStatus::Finished
                && $thirdPlaceGame->winner_entry_id !== null;

            if ($finalFinished && ! $thirdPlaceFinished) {
                return 'Completar partido por tercer puesto';
            }

            if (! $finalFinished && $thirdPlaceFinished) {
                return 'Completar final';
            }

            if (! $finalFinished && $thirdPlaceGame !== null) {
                return 'Continuar fase eliminatoria';
            }
        }

        if ($finalGame !== null) {
            return 'Ver llave';
        }

        $currentRoundComplete = ! Game::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('bracket_id')
            ->mainBracket()
            ->where('bracket_round', $currentRound)
            ->whereIn('status', [GameStatus::Pending, GameStatus::InProgress])
            ->exists();

        if ($currentRoundComplete) {
            return 'Generar siguiente ronda';
        }

        return 'Ver llave';
    }

    private static function resolveTeamKnockoutNextAction(Competition $competition): string
    {
        $currentRound = (int) TeamTie::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('bracket_id')
            ->mainBracket()
            ->max('bracket_round');

        if ($currentRound === 0) {
            return 'Ver llave';
        }

        $finalTeamTie = TeamTie::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('bracket_id')
            ->mainBracket()
            ->where('round', 'Final')
            ->first();

        $thirdPlaceMode = $competition->third_place_mode instanceof ThirdPlaceMode
            ? $competition->third_place_mode
            : ThirdPlaceMode::from((string) $competition->third_place_mode);

        $bracket = $competition->brackets()->first();
        $requiresThirdPlace = $bracket !== null
            && $thirdPlaceMode === ThirdPlaceMode::Playoff
            && BracketPodiumSupport::requiresPlayoffThirdPlace($competition, $bracket);

        if ($requiresThirdPlace) {
            $thirdPlaceTeamTie = BracketPodiumSupport::findThirdPlaceTeamTie($bracket);
            $finalFinished = $finalTeamTie !== null
                && $finalTeamTie->status === TeamTieStatus::Finished
                && $finalTeamTie->winner_entry_id !== null;
            $thirdPlaceFinished = $thirdPlaceTeamTie !== null
                && $thirdPlaceTeamTie->status === TeamTieStatus::Finished
                && $thirdPlaceTeamTie->winner_entry_id !== null;

            if ($finalFinished && ! $thirdPlaceFinished) {
                return 'Completar partido por tercer puesto';
            }

            if (! $finalFinished && $thirdPlaceFinished) {
                return 'Completar final';
            }

            if (! $finalFinished && $thirdPlaceTeamTie !== null) {
                return 'Continuar fase eliminatoria';
            }
        }

        if ($finalTeamTie !== null) {
            return 'Ver llave';
        }

        $currentRoundComplete = ! TeamTie::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('bracket_id')
            ->mainBracket()
            ->where('bracket_round', $currentRound)
            ->whereIn('status', [TeamTieStatus::Pending, TeamTieStatus::InProgress])
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

    private static function isTeamCompetition(Competition $competition): bool
    {
        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        return $type === CompetitionType::Team;
    }
}
