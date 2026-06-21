<?php

namespace Database\Seeders;

use App\Actions\Competition\CreateCompetitionAction;
use App\Actions\Game\RecordGameSetAction;
use App\Actions\Group\CreateGroupAction;
use App\Actions\Group\GenerateGroupRoundRobinGamesAction;
use App\Actions\GroupPlayer\AssignPlayerToGroupAction;
use App\Actions\Player\CreatePlayerAction;
use App\Actions\Registration\RegisterPlayerToCompetitionAction;
use App\Actions\Tournament\CreateTournamentAction;
use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use App\Enums\GameStatus;
use App\Enums\TournamentStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupPlayer;
use App\Models\Player;
use App\Models\Registration;
use App\Models\Tournament;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CompletedTournamentSeeder extends Seeder
{
    private const TOURNAMENT_NAME = 'Torneo Demo Completo';

    private const COMPETITION_NAME = 'Primera';

    /**
     * @var array<int, string>
     */
    private const PLAYER_NAMES = [
        'Carlos Perez',
        'Juan Gomez',
        'Pedro Ruiz',
        'Diego Silva',
        'Martin Castro',
        'Nicolas Torres',
        'Luis Lopez',
        'Marcos Diaz',
    ];

    /**
     * @var array<int, string>
     */
    private const GROUP_A_NAMES = [
        'Carlos Perez',
        'Juan Gomez',
        'Pedro Ruiz',
        'Diego Silva',
    ];

    /**
     * @var array<int, string>
     */
    private const GROUP_B_NAMES = [
        'Martin Castro',
        'Nicolas Torres',
        'Luis Lopez',
        'Marcos Diaz',
    ];

    /**
     * @var array<int, array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}>
     */
    private const SET_SCORES = [
        [[11, 7], [11, 8]],
        [[11, 5], [11, 9]],
        [[11, 6], [11, 4]],
    ];

    /**
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const GROUP_A_RESULTS = [
        ['Carlos Perez', 'Juan Gomez', 'Carlos Perez'],
        ['Carlos Perez', 'Pedro Ruiz', 'Carlos Perez'],
        ['Carlos Perez', 'Diego Silva', 'Carlos Perez'],
        ['Juan Gomez', 'Pedro Ruiz', 'Juan Gomez'],
        ['Juan Gomez', 'Diego Silva', 'Juan Gomez'],
        ['Pedro Ruiz', 'Diego Silva', 'Pedro Ruiz'],
    ];

    /**
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const GROUP_B_RESULTS = [
        ['Martin Castro', 'Nicolas Torres', 'Martin Castro'],
        ['Martin Castro', 'Luis Lopez', 'Martin Castro'],
        ['Martin Castro', 'Marcos Diaz', 'Martin Castro'],
        ['Nicolas Torres', 'Luis Lopez', 'Nicolas Torres'],
        ['Nicolas Torres', 'Marcos Diaz', 'Nicolas Torres'],
        ['Luis Lopez', 'Marcos Diaz', 'Luis Lopez'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            $this->command?->warn('CompletedTournamentSeeder está pensado solo para desarrollo/local.');

            return;
        }

        $summary = [
            'tournament_created' => 0,
            'competition_created' => 0,
            'players_created' => 0,
            'groups_created' => 0,
            'games_generated' => 0,
            'games_finished' => 0,
        ];

        $createTournament = app(CreateTournamentAction::class);
        $createCompetition = app(CreateCompetitionAction::class);
        $createPlayer = app(CreatePlayerAction::class);
        $registerPlayer = app(RegisterPlayerToCompetitionAction::class);
        $createGroup = app(CreateGroupAction::class);
        $assignPlayer = app(AssignPlayerToGroupAction::class);
        $generateRoundRobin = app(GenerateGroupRoundRobinGamesAction::class);
        $recordGameSet = app(RecordGameSetAction::class);

        [$tournament, $tournamentCreated] = $this->findOrCreateTournament($createTournament);
        $summary['tournament_created'] = $tournamentCreated ? 1 : 0;

        [$competition, $competitionCreated] = $this->findOrCreateCompetition($tournament, $createCompetition);
        $summary['competition_created'] = $competitionCreated ? 1 : 0;

        [$playersByName, $playersCreated] = $this->findOrCreatePlayers($createPlayer);
        $summary['players_created'] = $playersCreated;

        $this->registerPlayersToCompetition($competition, $playersByName, $registerPlayer);

        [$groupA, $groupACreated] = $this->findOrCreateGroup($competition, 'Grupo A', $createGroup);
        [$groupB, $groupBCreated] = $this->findOrCreateGroup($competition, 'Grupo B', $createGroup);
        $summary['groups_created'] = ($groupACreated ? 1 : 0) + ($groupBCreated ? 1 : 0);

        $this->assignPlayersToGroup(
            $competition,
            $groupA,
            $this->resolvePlayers($playersByName, self::GROUP_A_NAMES),
            $assignPlayer
        );
        $this->assignPlayersToGroup(
            $competition,
            $groupB,
            $this->resolvePlayers($playersByName, self::GROUP_B_NAMES),
            $assignPlayer
        );

        $summary['games_generated'] += $this->generateRoundRobinIfNeeded($groupA, $generateRoundRobin);
        $summary['games_generated'] += $this->generateRoundRobinIfNeeded($groupB, $generateRoundRobin);

        $summary['games_finished'] += $this->finishGroupGames(
            $groupA,
            self::GROUP_A_RESULTS,
            $playersByName,
            $recordGameSet
        );
        $summary['games_finished'] += $this->finishGroupGames(
            $groupB,
            self::GROUP_B_RESULTS,
            $playersByName,
            $recordGameSet
        );

        $this->printSummary($summary, $competition);
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
                'location' => 'Club Demo',
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
        CreateCompetitionAction $createCompetition
    ): array {
        $existingCompetition = Competition::query()
            ->where('tournament_id', $tournament->id)
            ->where('name', self::COMPETITION_NAME)
            ->first();

        if ($existingCompetition !== null) {
            return [$existingCompetition, false];
        }

        return [
            ($createCompetition)([
                'tournament_id' => $tournament->id,
                'name' => self::COMPETITION_NAME,
                'type' => CompetitionType::Singles,
                'category' => Str::lower(self::COMPETITION_NAME),
                'format' => CompetitionFormat::Manual,
                'sets_to_win' => 2,
                'points_per_set' => 11,
                'group_stage_best_of' => 3,
                'knockout_stage_best_of' => 3,
                'semifinal_best_of' => 3,
                'final_best_of' => 3,
            ]),
            true,
        ];
    }

    /**
     * @return array{0: array<string, Player>, 1: int}
     */
    private function findOrCreatePlayers(CreatePlayerAction $createPlayer): array
    {
        $playersByName = [];
        $createdCount = 0;

        foreach (self::PLAYER_NAMES as $index => $playerName) {
            [$firstName, $lastName] = explode(' ', $playerName, 2);
            $nickname = sprintf('completed-%d', $index + 1);

            $player = Player::query()
                ->where('nickname', $nickname)
                ->first();

            if ($player === null) {
                $player = ($createPlayer)([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'nickname' => $nickname,
                ]);
                $createdCount++;
            }

            $playersByName[$playerName] = $player;
        }

        return [$playersByName, $createdCount];
    }

    /**
     * @param  array<string, Player>  $playersByName
     */
    private function registerPlayersToCompetition(
        Competition $competition,
        array $playersByName,
        RegisterPlayerToCompetitionAction $registerPlayer
    ): void {
        foreach ($playersByName as $player) {
            $alreadyRegistered = Registration::query()
                ->where('competition_id', $competition->id)
                ->where('player_id', $player->id)
                ->exists();

            if (! $alreadyRegistered) {
                ($registerPlayer)([
                    'competition_id' => $competition->id,
                    'player_id' => $player->id,
                ]);
            }
        }
    }

    /**
     * @return array{0: Group, 1: bool}
     */
    private function findOrCreateGroup(
        Competition $competition,
        string $groupName,
        CreateGroupAction $createGroup
    ): array {
        $existingGroup = Group::query()
            ->where('competition_id', $competition->id)
            ->where('name', $groupName)
            ->first();

        if ($existingGroup !== null) {
            return [$existingGroup, false];
        }

        return [
            ($createGroup)([
                'competition_id' => $competition->id,
                'name' => $groupName,
            ]),
            true,
        ];
    }

    /**
     * @param  array<int, Player>  $players
     */
    private function assignPlayersToGroup(
        Competition $competition,
        Group $group,
        array $players,
        AssignPlayerToGroupAction $assignPlayer
    ): void {
        foreach ($players as $player) {
            $alreadyAssignedToThisGroup = GroupPlayer::query()
                ->where('group_id', $group->id)
                ->where('player_id', $player->id)
                ->exists();

            if ($alreadyAssignedToThisGroup) {
                continue;
            }

            $alreadyAssignedInCompetition = GroupPlayer::query()
                ->where('player_id', $player->id)
                ->whereHas('group', fn ($query) => $query->where('competition_id', $competition->id))
                ->exists();

            if (! $alreadyAssignedInCompetition) {
                ($assignPlayer)([
                    'group_id' => $group->id,
                    'player_id' => $player->id,
                ]);
            }
        }
    }

    /**
     * @param  array<string, Player>  $playersByName
     * @param  array<int, string>  $playerNames
     * @return array<int, Player>
     */
    private function resolvePlayers(array $playersByName, array $playerNames): array
    {
        return array_map(
            fn (string $playerName): Player => $playersByName[$playerName],
            $playerNames
        );
    }

    private function generateRoundRobinIfNeeded(
        Group $group,
        GenerateGroupRoundRobinGamesAction $generateRoundRobin
    ): int {
        if ($group->games()->exists()) {
            return 0;
        }

        return $generateRoundRobin($group)->count();
    }

    /**
     * @param  array<string, Player>  $playersByName
     * @param  array<int, array{0: string, 1: string, 2: string}>  $matchResults
     */
    private function finishGroupGames(
        Group $group,
        array $matchResults,
        array $playersByName,
        RecordGameSetAction $recordGameSet
    ): int {
        $games = $group->games()->get();
        $finishedCount = 0;

        foreach ($matchResults as $index => [$playerOneName, $playerTwoName, $winnerName]) {
            $playerOne = $playersByName[$playerOneName];
            $playerTwo = $playersByName[$playerTwoName];
            $winner = $playersByName[$winnerName];

            $game = $this->findGameBetween($games, $playerOne, $playerTwo);

            if ($this->finishGame($game, $winner, $recordGameSet, $index)) {
                $finishedCount++;
            }
        }

        return $finishedCount;
    }

    /**
     * @param  Collection<int, Game>  $games
     */
    private function findGameBetween(Collection $games, Player $left, Player $right): Game
    {
        $game = $games->first(
            fn (Game $candidate): bool => (
                (int) $candidate->player1_id === $left->id && (int) $candidate->player2_id === $right->id
            ) || (
                (int) $candidate->player1_id === $right->id && (int) $candidate->player2_id === $left->id
            )
        );

        if ($game === null) {
            throw new \RuntimeException(sprintf(
                'No se encontró partido entre %s y %s.',
                $left->first_name.' '.$left->last_name,
                $right->first_name.' '.$right->last_name,
            ));
        }

        return $game;
    }

    private function finishGame(
        Game $game,
        Player $winner,
        RecordGameSetAction $recordGameSet,
        int $scoreVariantIndex
    ): bool {
        $game->refresh();

        if ($game->status === GameStatus::Finished) {
            return false;
        }

        $setScores = self::SET_SCORES[$scoreVariantIndex % count(self::SET_SCORES)];
        $isWinnerPlayer1 = (int) $game->player1_id === $winner->id;

        foreach ($setScores as $setIndex => [$winnerScore, $loserScore]) {
            $game = ($recordGameSet)($game, [
                'set_number' => $setIndex + 1,
                'player1_score' => $isWinnerPlayer1 ? $winnerScore : $loserScore,
                'player2_score' => $isWinnerPlayer1 ? $loserScore : $winnerScore,
            ]);
        }

        return true;
    }

    /**
     * @param  array{
     *     tournament_created: int,
     *     competition_created: int,
     *     players_created: int,
     *     groups_created: int,
     *     games_generated: int,
     *     games_finished: int,
     * }  $summary
     */
    private function printSummary(array $summary, Competition $competition): void
    {
        $totalGames = Game::query()
            ->where('competition_id', $competition->id)
            ->count();

        $finishedGames = Game::query()
            ->where('competition_id', $competition->id)
            ->where('status', GameStatus::Finished)
            ->count();

        $this->command?->newLine();
        $this->command?->info('=== CompletedTournamentSeeder ===');
        $this->command?->line(sprintf('Torneo creado:       %d', $summary['tournament_created']));
        $this->command?->line(sprintf('Competencia creada: %d', $summary['competition_created']));
        $this->command?->line(sprintf('Jugadores creados:  %d', $summary['players_created']));
        $this->command?->line(sprintf('Grupos creados:     %d', $summary['groups_created']));
        $this->command?->line(sprintf('Partidos generados: %d', $summary['games_generated']));
        $this->command?->line(sprintf('Partidos finalizados: %d', $summary['games_finished']));
        $this->command?->line(sprintf('Total partidos en competencia: %d (%d finalizados)', $totalGames, $finishedGames));
        $this->command?->newLine();
    }
}
