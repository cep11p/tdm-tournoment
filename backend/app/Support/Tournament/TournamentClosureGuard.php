<?php

namespace App\Support\Tournament;

use App\Enums\GameStatus;
use App\Enums\TeamTieStatus;
use App\Enums\ThirdPlaceMode;
use App\Enums\TournamentStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Models\TeamTie;
use App\Models\Tournament;
use App\Support\Bracket\BracketPodiumSupport;
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
     *     results: list<array<string, mixed>>,
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
            ->withCount([
                'entries as registrations_count',
                'games',
                'teamTies as team_ties_count',
            ])
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
                        self::incompleteCompetitionMessage($competition),
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

            if ($competition->isTeam()) {
                $hasOpenGames = TeamTie::query()
                    ->where('competition_id', $competition->id)
                    ->whereIn('status', [
                        TeamTieStatus::Pending,
                        TeamTieStatus::Ready,
                        TeamTieStatus::InProgress,
                    ])
                    ->exists();
            } else {
                $hasOpenGames = Game::query()
                    ->where('competition_id', $competition->id)
                    ->whereIn('status', [GameStatus::Pending, GameStatus::InProgress])
                    ->exists();
            }

            if ($hasOpenGames) {
                throw ValidationException::withMessages([
                    'tournament' => [
                        sprintf(
                            'La competencia «%s» tiene %s pendientes.',
                            $competition->name,
                            $competition->isTeam() ? 'enfrentamientos' : 'partidos',
                        ),
                    ],
                ]);
            }

            $completedCompetitions++;
            $results[] = self::buildCompetitionResultEntry($competition, $result);
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
     *     results: list<array<string, mixed>>,
     * }|null
     */
    public static function buildSummaryForClosedTournament(Tournament $tournament): ?array
    {
        if ($tournament->status !== TournamentStatus::Finished) {
            return null;
        }

        /** @var Collection<int, Competition> $competitions */
        $competitions = $tournament->competitions()
            ->withCount([
                'entries as registrations_count',
                'games',
                'teamTies as team_ties_count',
            ])
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
            $results[] = self::buildCompetitionResultEntry($competition, $result);
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
        if ($competition->isTeam()) {
            return (int) $competition->registrations_count === 0
                && (int) ($competition->team_ties_count ?? 0) === 0;
        }

        return (int) $competition->registrations_count === 0
            && (int) $competition->games_count === 0;
    }

    /**
     * @param  array{
     *     champion: array{
     *         competition_entry_id: int,
     *         display_name: string,
     *         id: int|null,
     *         name: string|null,
     *     },
     *     runner_up: array{
     *         competition_entry_id: int,
     *         display_name: string,
     *         id: int|null,
     *         name: string|null,
     *     },
     *     third_place_mode?: string,
     *     third_place?: list<array<string, mixed>>,
     *     fourth_place: array<string, mixed>|null,
     * }  $result
     * @return array<string, mixed>
     */
    private static function buildCompetitionResultEntry(Competition $competition, array $result): array
    {
        $champion = $result['champion'];
        $runnerUp = $result['runner_up'];

        return [
            'competition_id' => $competition->id,
            'competition_name' => $competition->name,
            'champion_entry_id' => $champion['competition_entry_id'],
            'champion_display_name' => $champion['display_name'],
            'champion_id' => $champion['id'],
            'champion_name' => $champion['name'] ?? $champion['display_name'],
            'runner_up_entry_id' => $runnerUp['competition_entry_id'],
            'runner_up_display_name' => $runnerUp['display_name'],
            'runner_up_id' => $runnerUp['id'],
            'runner_up_name' => $runnerUp['name'] ?? $runnerUp['display_name'],
            'third_place_mode' => $result['third_place_mode'] ?? 'none',
            'third_place' => $result['third_place'] ?? [],
            'fourth_place' => $result['fourth_place'] ?? null,
        ];
    }

    private static function incompleteCompetitionMessage(Competition $competition): string
    {
        $thirdPlaceMode = $competition->third_place_mode instanceof ThirdPlaceMode
            ? $competition->third_place_mode
            : ThirdPlaceMode::from((string) $competition->third_place_mode);

        if ($thirdPlaceMode === ThirdPlaceMode::Playoff) {
            $bracket = $competition->brackets()->first();

            if ($bracket !== null && BracketPodiumSupport::requiresPlayoffThirdPlace($competition, $bracket)) {
                if ($competition->isTeam()) {
                    $finalFinished = TeamTie::query()
                        ->where('competition_id', $competition->id)
                        ->where('bracket_id', $bracket->id)
                        ->mainBracket()
                        ->where('round', 'Final')
                        ->where('status', TeamTieStatus::Finished)
                        ->whereNotNull('winner_entry_id')
                        ->exists();

                    $thirdPlaceTeamTie = BracketPodiumSupport::findThirdPlaceTeamTie($bracket);
                    $thirdPlacePending = $thirdPlaceTeamTie !== null
                        && ($thirdPlaceTeamTie->status !== TeamTieStatus::Finished || $thirdPlaceTeamTie->winner_entry_id === null);

                    if ($finalFinished && $thirdPlacePending) {
                        return sprintf(
                            'La competencia «%s» aún tiene pendiente el enfrentamiento por el tercer puesto.',
                            $competition->name,
                        );
                    }
                } else {
                    $finalFinished = Game::query()
                        ->where('competition_id', $competition->id)
                        ->whereNotNull('bracket_id')
                        ->mainBracket()
                        ->where('round', 'Final')
                        ->where('status', GameStatus::Finished)
                        ->whereNotNull('winner_entry_id')
                        ->exists();

                    $thirdPlaceGame = BracketPodiumSupport::findThirdPlaceGame($bracket);
                    $thirdPlacePending = $thirdPlaceGame !== null
                        && ($thirdPlaceGame->status !== GameStatus::Finished || $thirdPlaceGame->winner_entry_id === null);

                    if ($finalFinished && $thirdPlacePending) {
                        return sprintf(
                            'La competencia «%s» aún tiene pendiente el partido por tercer puesto.',
                            $competition->name,
                        );
                    }
                }
            }
        }

        return sprintf(
            'No se puede finalizar el torneo porque la competencia «%s» no está finalizada.',
            $competition->name,
        );
    }
}
