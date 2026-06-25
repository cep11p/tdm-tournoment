<?php

namespace Database\Seeders;

use App\Actions\Competition\CreateCompetitionAction;
use App\Actions\Player\CreatePlayerAction;
use App\Actions\Registration\RegisterPlayerToCompetitionAction;
use App\Actions\Tournament\CreateTournamentAction;
use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use App\Enums\TournamentStatus;
use App\Models\Competition;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Tournament;
use Database\Seeders\Support\FriendlyTournamentRoster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FriendlyTournamentSeeder extends Seeder
{
    private const TOURNAMENT_NAME = 'Torneo Amistoso';

    private const TOURNAMENT_LOCATION = 'Club Amistoso';

    public function run(): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            $this->command?->warn('FriendlyTournamentSeeder está pensado solo para desarrollo/local/testing.');

            return;
        }

        $createTournament = app(CreateTournamentAction::class);
        $createCompetition = app(CreateCompetitionAction::class);
        $createPlayer = app(CreatePlayerAction::class);
        $registerPlayer = app(RegisterPlayerToCompetitionAction::class);

        $summary = [
            'tournament_created' => false,
            'competitions_created' => 0,
            'competitions_reused' => 0,
            'players_created' => 0,
            'players_reused' => 0,
            'registrations_created' => 0,
            'registrations_reused' => 0,
            'registrations_by_category' => [],
        ];

        [$tournament, $summary['tournament_created']] = $this->findOrCreateTournament($createTournament);

        $playersByFullName = $this->findOrCreateUniquePlayers(
            FriendlyTournamentRoster::PLAYERS_BY_CATEGORY,
            $createPlayer,
            $summary
        );

        foreach (FriendlyTournamentRoster::PLAYERS_BY_CATEGORY as $category => $playerNames) {
            $competitionName = FriendlyTournamentRoster::COMPETITION_NAMES[$category];

            [$competition, $competitionCreated] = $this->findOrCreateCompetition(
                $tournament,
                $competitionName,
                $category,
                $createCompetition
            );

            if ($competitionCreated) {
                $summary['competitions_created']++;
            } else {
                $summary['competitions_reused']++;
            }

            $categorySummary = $this->registerPlayersToCompetition(
                $competition,
                $playerNames,
                $playersByFullName,
                $registerPlayer
            );

            $summary['registrations_created'] += $categorySummary['created'];
            $summary['registrations_reused'] += $categorySummary['reused'];
            $summary['registrations_by_category'][$category] = [
                'competition' => $competitionName,
                'total' => count($playerNames),
                'created' => $categorySummary['created'],
                'reused' => $categorySummary['reused'],
            ];
        }

        $this->printSummary($summary);
    }

    /**
     * @return array{0: Tournament, 1: bool}
     */
    private function findOrCreateTournament(CreateTournamentAction $createTournament): array
    {
        $existingTournament = Tournament::query()
            ->where('name', self::TOURNAMENT_NAME)
            ->first();

        if ($existingTournament !== null) {
            return [$existingTournament, false];
        }

        return [
            ($createTournament)([
                'name' => self::TOURNAMENT_NAME,
                'location' => self::TOURNAMENT_LOCATION,
                'start_date' => now()->toDateString(),
                'status' => TournamentStatus::InProgress,
            ]),
            true,
        ];
    }

    /**
     * @return array{0: Competition, 1: bool}
     */
    private function findOrCreateCompetition(
        Tournament $tournament,
        string $competitionName,
        string $category,
        CreateCompetitionAction $createCompetition
    ): array {
        $existingCompetition = Competition::query()
            ->where('tournament_id', $tournament->id)
            ->where('name', $competitionName)
            ->first();

        if ($existingCompetition !== null) {
            return [$existingCompetition, false];
        }

        return [
            ($createCompetition)([
                'tournament_id' => $tournament->id,
                'name' => $competitionName,
                'type' => CompetitionType::Singles,
                'category' => $category,
                'format' => CompetitionFormat::Manual,
                'points_per_set' => 11,
                'group_stage_best_of' => 3,
                'knockout_stage_best_of' => 3,
                'semifinal_best_of' => 3,
                'final_best_of' => 3,
                'qualified_per_group' => 2,
            ]),
            true,
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $playersByCategory
     * @param  array{
     *     players_created: int,
     *     players_reused: int,
     * }  $summary
     * @return array<string, Player>
     */
    private function findOrCreateUniquePlayers(
        array $playersByCategory,
        CreatePlayerAction $createPlayer,
        array &$summary
    ): array {
        $uniqueFullNames = [];

        foreach ($playersByCategory as $playerNames) {
            foreach ($playerNames as $fullName) {
                $uniqueFullNames[$fullName] = true;
            }
        }

        $playersByFullName = [];

        foreach (array_keys($uniqueFullNames) as $fullName) {
            [$firstName, $lastName] = $this->splitPlayerName($fullName);
            $nickname = $this->buildNickname($fullName);

            $player = Player::query()
                ->where('nickname', $nickname)
                ->first();

            if ($player === null) {
                $player = ($createPlayer)([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'nickname' => $nickname,
                ]);
                $summary['players_created']++;
            } else {
                $summary['players_reused']++;
            }

            $playersByFullName[$fullName] = $player;
        }

        return $playersByFullName;
    }

    /**
     * @param  array<int, string>  $playerNames
     * @param  array<string, Player>  $playersByFullName
     * @return array{created: int, reused: int}
     */
    private function registerPlayersToCompetition(
        Competition $competition,
        array $playerNames,
        array $playersByFullName,
        RegisterPlayerToCompetitionAction $registerPlayer
    ): array {
        $created = 0;
        $reused = 0;

        foreach ($playerNames as $fullName) {
            $player = $playersByFullName[$fullName];

            $alreadyRegistered = Registration::query()
                ->where('competition_id', $competition->id)
                ->where('player_id', $player->id)
                ->exists();

            if ($alreadyRegistered) {
                $reused++;

                continue;
            }

            ($registerPlayer)([
                'competition_id' => $competition->id,
                'player_id' => $player->id,
            ]);
            $created++;
        }

        return [
            'created' => $created,
            'reused' => $reused,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitPlayerName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);

        return [
            $parts[0],
            $parts[1] ?? 'Sin apellido',
        ];
    }

    private function buildNickname(string $fullName): string
    {
        return 'amistoso-'.Str::slug($fullName);
    }

    /**
     * @param  array{
     *     tournament_created: bool,
     *     competitions_created: int,
     *     competitions_reused: int,
     *     players_created: int,
     *     players_reused: int,
     *     registrations_created: int,
     *     registrations_reused: int,
     *     registrations_by_category: array<string, array{
     *         competition: string,
     *         total: int,
     *         created: int,
     *         reused: int,
     *     }>,
     * }  $summary
     */
    private function printSummary(array $summary): void
    {
        $this->command?->newLine();
        $this->command?->info('=== '.self::TOURNAMENT_NAME.' ===');
        $this->command?->line(sprintf(
            'Torneo:             %s',
            $summary['tournament_created'] ? 'creado' : 'reutilizado'
        ));
        $this->command?->line(sprintf(
            'Competencias:       %d creadas, %d reutilizadas',
            $summary['competitions_created'],
            $summary['competitions_reused']
        ));
        $this->command?->line(sprintf(
            'Jugadores:          %d creados, %d reutilizados',
            $summary['players_created'],
            $summary['players_reused']
        ));
        $this->command?->line(sprintf(
            'Inscripciones:      %d nuevas, %d reutilizadas',
            $summary['registrations_created'],
            $summary['registrations_reused']
        ));
        $this->command?->newLine();
        $this->command?->info('Inscriptos por categoría:');

        foreach ($summary['registrations_by_category'] as $category => $categorySummary) {
            $this->command?->line(sprintf(
                '  %-8s (%s): %d total (%d nuevas, %d existentes)',
                ucfirst($category).':',
                $categorySummary['competition'],
                $categorySummary['total'],
                $categorySummary['created'],
                $categorySummary['reused']
            ));
        }

        $this->command?->newLine();
    }
}
