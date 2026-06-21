<?php

namespace Database\Seeders\Support;

use App\Actions\Competition\CreateCompetitionAction;
use App\Actions\Competition\UpdateCompetitionAction;
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
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class DemoScenarioBuilder
{
    /**
     * @var array<int, array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}>
     */
    private const SET_SCORES = [
        [[11, 7], [11, 8]],
        [[11, 5], [11, 9]],
        [[11, 6], [11, 4]],
    ];

    public function seed(DemoScenarioConfig $config, ?Command $command = null): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            $command?->warn('Los seeders demo están pensados solo para desarrollo/local/testing.');

            return;
        }

        $summary = [
            'tournament_created' => false,
            'competition_created' => false,
            'players_created' => 0,
            'groups_created' => 0,
            'games_generated' => 0,
            'games_finished' => 0,
        ];

        $createTournament = app(CreateTournamentAction::class);
        $createCompetition = app(CreateCompetitionAction::class);
        $updateCompetition = app(UpdateCompetitionAction::class);
        $createPlayer = app(CreatePlayerAction::class);
        $registerPlayer = app(RegisterPlayerToCompetitionAction::class);
        $createGroup = app(CreateGroupAction::class);
        $assignPlayer = app(AssignPlayerToGroupAction::class);
        $generateRoundRobin = app(GenerateGroupRoundRobinGamesAction::class);
        $recordGameSet = app(RecordGameSetAction::class);

        [$tournament, $summary['tournament_created']] = $this->findOrCreateTournament(
            $config->tournamentName,
            $createTournament
        );

        [$competition, $summary['competition_created']] = $this->findOrCreateCompetition(
            $tournament,
            $config,
            $createCompetition
        );

        $this->ensureQualifiedPerGroup($competition, $config->qualifiedPerGroup, $updateCompetition);

        $allPlayersByName = [];

        foreach ($config->groups as $groupDefinition) {
            [$groupPlayersByName, $playersCreated] = $this->findOrCreatePlayers(
                $groupDefinition['players'],
                $config->nicknamePrefix,
                $groupDefinition['name'],
                $createPlayer
            );
            $summary['players_created'] += $playersCreated;

            foreach ($groupPlayersByName as $playerName => $player) {
                $allPlayersByName[$playerName] = $player;
            }

            $this->registerPlayersToCompetition(
                $competition,
                array_values($groupPlayersByName),
                $registerPlayer
            );

            [$group, $groupCreated] = $this->findOrCreateGroup(
                $competition,
                $groupDefinition['name'],
                $createGroup
            );
            $summary['groups_created'] += $groupCreated ? 1 : 0;

            $this->assignPlayersToGroup(
                $competition,
                $group,
                array_values($groupPlayersByName),
                $assignPlayer
            );

            $summary['games_generated'] += $this->generateRoundRobinIfNeeded($group, $generateRoundRobin);

            $summary['games_finished'] += $this->finishGroupGames(
                $group,
                $this->buildRoundRobinResults($groupDefinition['players']),
                $groupPlayersByName,
                $recordGameSet
            );
        }

        $this->printSummary($config, $competition, $summary, $command);
    }

    /**
     * @param  array<int, string>  $playerNames
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function buildRoundRobinResults(array $playerNames): array
    {
        $results = [];
        $playerCount = count($playerNames);

        for ($index = 0; $index < $playerCount; $index++) {
            for ($pairIndex = $index + 1; $pairIndex < $playerCount; $pairIndex++) {
                $results[] = [
                    $playerNames[$index],
                    $playerNames[$pairIndex],
                    $playerNames[$index],
                ];
            }
        }

        return $results;
    }

    /**
     * @return array{0: Tournament, 1: bool}
     */
    private function findOrCreateTournament(string $tournamentName, CreateTournamentAction $createTournament): array
    {
        $existingTournament = Tournament::query()
            ->where('name', $tournamentName)
            ->first();

        if ($existingTournament !== null) {
            return [$existingTournament, false];
        }

        return [
            ($createTournament)([
                'name' => $tournamentName,
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
        DemoScenarioConfig $config,
        CreateCompetitionAction $createCompetition
    ): array {
        $existingCompetition = Competition::query()
            ->where('tournament_id', $tournament->id)
            ->where('name', $config->competitionName)
            ->first();

        if ($existingCompetition !== null) {
            return [$existingCompetition, false];
        }

        return [
            ($createCompetition)([
                'tournament_id' => $tournament->id,
                'name' => $config->competitionName,
                'type' => CompetitionType::Singles,
                'category' => Str::slug($config->competitionName),
                'format' => CompetitionFormat::Manual,
                'sets_to_win' => 2,
                'points_per_set' => 11,
                'qualified_per_group' => $config->qualifiedPerGroup,
            ]),
            true,
        ];
    }

    private function ensureQualifiedPerGroup(
        Competition $competition,
        int $qualifiedPerGroup,
        UpdateCompetitionAction $updateCompetition
    ): void {
        if ((int) $competition->qualified_per_group === $qualifiedPerGroup) {
            return;
        }

        if ($competition->brackets()->exists()) {
            return;
        }

        ($updateCompetition)($competition, [
            'qualified_per_group' => $qualifiedPerGroup,
        ]);
    }

    /**
     * @param  array<int, string>  $playerNames
     * @return array{0: array<string, Player>, 1: int}
     */
    private function findOrCreatePlayers(
        array $playerNames,
        string $nicknamePrefix,
        string $groupName,
        CreatePlayerAction $createPlayer
    ): array {
        $playersByName = [];
        $createdCount = 0;
        $groupSlug = Str::slug($groupName);

        foreach ($playerNames as $index => $playerName) {
            [$firstName, $lastName] = $this->splitPlayerName($playerName);
            $nickname = sprintf('%s-%s-%02d', $nicknamePrefix, $groupSlug, $index + 1);

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
     * @return array{0: string, 1: string}
     */
    private function splitPlayerName(string $playerName): array
    {
        $parts = explode(' ', $playerName, 2);

        return [
            $parts[0],
            $parts[1] ?? 'Demo',
        ];
    }

    /**
     * @param  array<int, Player>  $players
     */
    private function registerPlayersToCompetition(
        Competition $competition,
        array $players,
        RegisterPlayerToCompetitionAction $registerPlayer
    ): void {
        foreach ($players as $player) {
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
     *     tournament_created: bool,
     *     competition_created: bool,
     *     players_created: int,
     *     groups_created: int,
     *     games_generated: int,
     *     games_finished: int,
     * }  $summary
     */
    private function printSummary(
        DemoScenarioConfig $config,
        Competition $competition,
        array $summary,
        ?Command $command
    ): void {
        $totalGames = Game::query()
            ->where('competition_id', $competition->id)
            ->count();

        $finishedGames = Game::query()
            ->where('competition_id', $competition->id)
            ->where('status', GameStatus::Finished)
            ->count();

        $bracketStatus = $config->bracketShouldSucceed
            ? 'Bracket válido (2, 4 u 8 clasificados)'
            : 'Bracket debe fallar hasta PR2 (BYEs)';

        if ($config->bracketNote !== null) {
            $bracketStatus .= ' — '.$config->bracketNote;
        }

        $command?->newLine();
        $command?->info('=== '.$config->tournamentName.' ===');
        $command?->line(sprintf(
            'Torneo:             %s',
            $summary['tournament_created'] ? 'creado' : 'existente'
        ));
        $command?->line(sprintf(
            'Competencia:        %s (%s)',
            $summary['competition_created'] ? 'creada' : 'existente',
            $config->competitionName
        ));
        $command?->line(sprintf('Grupos:             %d', $config->groupCount()));
        $command?->line(sprintf('Jugadores:          %d', $config->totalPlayers()));
        $command?->line(sprintf('qualified_per_group: %d', $config->qualifiedPerGroup));
        $command?->line(sprintf('Clasificados est.:  %d', $config->estimatedQualifiers()));
        $command?->line(sprintf('Partidos generados: %d', $summary['games_generated']));
        $command?->line(sprintf('Partidos finalizados: %d', $summary['games_finished']));
        $command?->line(sprintf('Total partidos:     %d (%d finalizados)', $totalGames, $finishedGames));
        $command?->line(sprintf('Bracket:            %s', $bracketStatus));
        $command?->newLine();
    }
}
