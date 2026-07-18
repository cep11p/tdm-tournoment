<?php

namespace App\Support\Tournament;

use App\Enums\GameStatus;
use App\Enums\TournamentStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Tournament;
use App\Support\Competition\CompetitionResultResolver;
use App\Support\Competition\CompetitionStatusResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class TournamentClosureGuard
{
    /**
     * @return array{
     *     competitions_count: int,
     *     completed_competitions: int,
     *     unused_competitions: int,
     *     games_count: int,
     *     results: list<array{
     *         competition_id: int,
     *         competition_name: string,
     *         champion_id: int,
     *         champion_name: string,
     *         runner_up_id: int,
     *         runner_up_name: string,
     *     }>,
     * }
     */
    public static function ensureCanClose(Tournament $tournament): array
    {
        if ($tournament->status === TournamentStatus::Finished) {
            throw ValidationException::withMessages([
                'tournament' => ['El torneo ya está finalizado.'],
            ]);
        }

        /** @var Collection<int, Competition> $competitions */
        $competitions = $tournament->competitions()
            ->withCount(['registrations', 'games'])
            ->orderBy('id')
            ->get();

        if ($competitions->isEmpty()) {
            throw ValidationException::withMessages([
                'tournament' => ['El torneo no tiene competencias.'],
            ]);
        }

        $unusedCompetitions = 0;
        $completedCompetitions = 0;
        $results = [];

        foreach ($competitions as $competition) {
            if (self::isUnusedCompetition($competition)) {
                $unusedCompetitions++;

                continue;
            }

            $status = CompetitionStatusResolver::resolve($competition);

            if ($status['code'] !== 'completed') {
                throw ValidationException::withMessages([
                    'tournament' => [
                        sprintf(
                            'No se puede finalizar el torneo porque la competencia «%s» no está finalizada.',
                            $competition->name,
                        ),
                    ],
                ]);
            }

            $result = CompetitionResultResolver::resolve($competition);

            if ($result === null) {
                throw ValidationException::withMessages([
                    'tournament' => [
                        sprintf(
                            'La competencia «%s» no tiene un campeón definido.',
                            $competition->name,
                        ),
                    ],
                ]);
            }

            $hasOpenGames = Game::query()
                ->where('competition_id', $competition->id)
                ->whereIn('status', [GameStatus::Pending, GameStatus::InProgress])
                ->exists();

            if ($hasOpenGames) {
                throw ValidationException::withMessages([
                    'tournament' => [
                        sprintf(
                            'La competencia «%s» tiene partidos pendientes.',
                            $competition->name,
                        ),
                    ],
                ]);
            }

            $completedCompetitions++;
            $results[] = [
                'competition_id' => $competition->id,
                'competition_name' => $competition->name,
                'champion_id' => $result['champion']['id'],
                'champion_name' => $result['champion']['name'],
                'runner_up_id' => $result['runner_up']['id'],
                'runner_up_name' => $result['runner_up']['name'],
            ];
        }

        return [
            'competitions_count' => $competitions->count(),
            'completed_competitions' => $completedCompetitions,
            'unused_competitions' => $unusedCompetitions,
            'games_count' => (int) Game::query()
                ->whereIn('competition_id', $competitions->pluck('id'))
                ->count(),
            'results' => $results,
        ];
    }

    /**
     * @return array{
     *     competitions_count: int,
     *     completed_competitions: int,
     *     unused_competitions: int,
     *     games_count: int,
     *     results: list<array{
     *         competition_id: int,
     *         competition_name: string,
     *         champion_id: int,
     *         champion_name: string,
     *         runner_up_id: int,
     *         runner_up_name: string,
     *     }>,
     * }|null
     */
    public static function buildSummaryForClosedTournament(Tournament $tournament): ?array
    {
        if ($tournament->status !== TournamentStatus::Finished) {
            return null;
        }

        /** @var Collection<int, Competition> $competitions */
        $competitions = $tournament->competitions()
            ->withCount(['registrations', 'games'])
            ->orderBy('id')
            ->get();

        if ($competitions->isEmpty()) {
            return null;
        }

        $unusedCompetitions = 0;
        $completedCompetitions = 0;
        $results = [];

        foreach ($competitions as $competition) {
            if (self::isUnusedCompetition($competition)) {
                $unusedCompetitions++;

                continue;
            }

            $result = CompetitionResultResolver::resolve($competition);

            if ($result === null) {
                continue;
            }

            $completedCompetitions++;
            $results[] = [
                'competition_id' => $competition->id,
                'competition_name' => $competition->name,
                'champion_id' => $result['champion']['id'],
                'champion_name' => $result['champion']['name'],
                'runner_up_id' => $result['runner_up']['id'],
                'runner_up_name' => $result['runner_up']['name'],
            ];
        }

        return [
            'competitions_count' => $competitions->count(),
            'completed_competitions' => $completedCompetitions,
            'unused_competitions' => $unusedCompetitions,
            'games_count' => (int) Game::query()
                ->whereIn('competition_id', $competitions->pluck('id'))
                ->count(),
            'results' => $results,
        ];
    }

    private static function isUnusedCompetition(Competition $competition): bool
    {
        return (int) $competition->registrations_count === 0
            && (int) $competition->games_count === 0;
    }
}
