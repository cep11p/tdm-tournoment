<?php

namespace Database\Seeders\Support;

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
use Illuminate\Console\Command;

final class MisSeedsDePruebaBuilder
{
    private const TOURNAMENT_LOCATION = 'Club Mis Pruebas';

    /**
     * @var array<string, int>
     */
    private const GROUPS_COUNT_BY_CATEGORY = [
        'primera' => 1,
        'segunda' => 5,
        'tercera' => 6,
        'cuarta' => 4,
    ];

    /**
     * @var array<string, array<string, int>>
     */
    private const PARTIAL_RESULTS_BY_CATEGORY = [
        'primera' => [
            'Grupo A' => 2,
        ],
        'segunda' => [
            'Grupo A' => 2,
            'Grupo B' => 2,
        ],
        'tercera' => [
            'Grupo A' => 3,
            'Grupo B' => 1,
        ],
        'cuarta' => [
            'Grupo A' => 2,
        ],
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
     * @var array<string, Player>|null
     */
    private ?array $playersByFullName = null;

    public function __construct(
        private readonly ?Command $command = null,
    ) {}

    /**
     * @return array{
     *     tournament: Tournament,
     *     tournament_created: bool,
     *     competitions: array<string, Competition>,
     *     competitions_created: int,
     *     competitions_reused: int,
     *     registrations_created: int,
     *     registrations_reused: int,
     * }
     */
    public function seedBaseTournament(string $tournamentName): array
    {
        $createTournament = app(CreateTournamentAction::class);
        $createCompetition = app(CreateCompetitionAction::class);

        [$tournament, $tournamentCreated] = $this->findOrCreateTournament($tournamentName, $createTournament);

        $this->findOrCreatePlayersFromRoster();

        $competitions = [];
        $competitionsCreated = 0;
        $competitionsReused = 0;
        $registrationsCreated = 0;
        $registrationsReused = 0;

        foreach (FriendlyTournamentRoster::PLAYERS_BY_CATEGORY as $category => $playerNames) {
            [$competition, $competitionCreated] = $this->findOrCreateCompetition(
                $tournament,
                $category,
                $createCompetition,
            );

            $competitions[$category] = $competition;

            if ($competitionCreated) {
                $competitionsCreated++;
            } else {
                $competitionsReused++;
            }

            $registrationSummary = $this->registerCategoryPlayers($competition, $category);
            $registrationsCreated += $registrationSummary['created'];
            $registrationsReused += $registrationSummary['reused'];
        }

        return [
            'tournament' => $tournament,
            'tournament_created' => $tournamentCreated,
            'competitions' => $competitions,
            'competitions_created' => $competitionsCreated,
            'competitions_reused' => $competitionsReused,
            'registrations_created' => $registrationsCreated,
            'registrations_reused' => $registrationsReused,
        ];
    }

    /**
     * @return array{groups_created: int, assignments_created: int}
     */
    public function assignDeterministicGroups(Competition $competition, string $category): array
    {
        $groupsCount = self::GROUPS_COUNT_BY_CATEGORY[$category];
        $playerNames = FriendlyTournamentRoster::PLAYERS_BY_CATEGORY[$category];
        $players = array_map(
            fn (string $fullName): Player => $this->playersByFullName[$fullName],
            $playerNames,
        );

        $groupSizes = $this->calculateBalancedGroupSizes(count($players), $groupsCount);
        $createGroup = app(CreateGroupAction::class);
        $assignPlayer = app(AssignPlayerToGroupAction::class);

        $groupsCreated = 0;
        $assignmentsCreated = 0;
        $playerOffset = 0;

        for ($groupIndex = 0; $groupIndex < $groupsCount; $groupIndex++) {
            $groupName = $this->groupNameForIndex($groupIndex);

            [$group, $groupCreated] = $this->findOrCreateGroup($competition, $groupName, $createGroup);

            if ($groupCreated) {
                $groupsCreated++;
            }

            $groupSize = $groupSizes[$groupIndex];
            $groupPlayers = array_slice($players, $playerOffset, $groupSize);
            $playerOffset += $groupSize;

            foreach ($groupPlayers as $player) {
                if ($this->assignPlayerToGroupIfNeeded($competition, $group, $player, $assignPlayer)) {
                    $assignmentsCreated++;
                }
            }
        }

        return [
            'groups_created' => $groupsCreated,
            'assignments_created' => $assignmentsCreated,
        ];
    }

    public function generateRoundRobinIfNeeded(Group $group): int
    {
        if ($group->games()->exists()) {
            return 0;
        }

        return app(GenerateGroupRoundRobinGamesAction::class)($group)->count();
    }

    public function finishFirstPendingGames(Group $group, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $alreadyFinished = $group->games()
            ->where('status', GameStatus::Finished)
            ->count();

        $remaining = max(0, $limit - $alreadyFinished);

        if ($remaining === 0) {
            return 0;
        }

        $pendingGames = $group->games()
            ->orderBy('id')
            ->get()
            ->filter(fn (Game $game): bool => $game->status !== GameStatus::Finished)
            ->take($remaining)
            ->values();

        $finishedCount = 0;

        foreach ($pendingGames as $index => $game) {
            if ($this->finishGame($game, $index)) {
                $finishedCount++;
            }
        }

        return $finishedCount;
    }

    /**
     * @param  array<string, Competition>  $competitions
     * @return array{groups_created: int, assignments_created: int}
     */
    public function assignAllDeterministicGroups(array $competitions): array
    {
        $totals = [
            'groups_created' => 0,
            'assignments_created' => 0,
        ];

        foreach (array_keys(FriendlyTournamentRoster::PLAYERS_BY_CATEGORY) as $category) {
            $summary = $this->assignDeterministicGroups($competitions[$category], $category);
            $totals['groups_created'] += $summary['groups_created'];
            $totals['assignments_created'] += $summary['assignments_created'];
        }

        return $totals;
    }

    /**
     * @param  array<string, Competition>  $competitions
     */
    public function generateAllRoundRobinGames(array $competitions): int
    {
        $generated = 0;

        foreach ($competitions as $competition) {
            $groups = Group::query()
                ->where('competition_id', $competition->id)
                ->orderBy('id')
                ->get();

            foreach ($groups as $group) {
                $generated += $this->generateRoundRobinIfNeeded($group);
            }
        }

        return $generated;
    }

    /**
     * @param  array<string, Competition>  $competitions
     */
    public function finishPartialResults(array $competitions): int
    {
        $finishedCount = 0;

        foreach (self::PARTIAL_RESULTS_BY_CATEGORY as $category => $groupsConfig) {
            $competition = $competitions[$category];

            foreach ($groupsConfig as $groupName => $gamesLimit) {
                $group = Group::query()
                    ->where('competition_id', $competition->id)
                    ->where('name', $groupName)
                    ->first();

                if ($group === null) {
                    continue;
                }

                $finishedCount += $this->finishFirstPendingGames($group, $gamesLimit);
            }
        }

        return $finishedCount;
    }

    /**
     * @return array{0: Tournament, 1: bool}
     */
    public function findOrCreateTournament(string $name, CreateTournamentAction $createTournament): array
    {
        $existingTournament = Tournament::query()
            ->where('name', $name)
            ->first();

        if ($existingTournament !== null) {
            return [$existingTournament, false];
        }

        return [
            ($createTournament)([
                'name' => $name,
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
    public function findOrCreateCompetition(
        Tournament $tournament,
        string $category,
        CreateCompetitionAction $createCompetition,
    ): array {
        $competitionName = FriendlyTournamentRoster::COMPETITION_NAMES[$category];

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
     * @return array<string, Player>
     */
    public function findOrCreatePlayersFromRoster(): array
    {
        if ($this->playersByFullName !== null) {
            return $this->playersByFullName;
        }

        $createPlayer = app(CreatePlayerAction::class);
        $uniqueFullNames = [];

        foreach (FriendlyTournamentRoster::PLAYERS_BY_CATEGORY as $playerNames) {
            foreach ($playerNames as $fullName) {
                $uniqueFullNames[$fullName] = true;
            }
        }

        $playersByFullName = [];

        foreach (array_keys($uniqueFullNames) as $fullName) {
            [$firstName, $lastName] = $this->splitPlayerName($fullName);

            $player = Player::query()
                ->where('first_name', $firstName)
                ->where('last_name', $lastName)
                ->first();

            if ($player === null) {
                $player = ($createPlayer)([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'nickname' => null,
                ]);
            }

            $playersByFullName[$fullName] = $player;
        }

        $this->playersByFullName = $playersByFullName;

        return $playersByFullName;
    }

    /**
     * @return array{created: int, reused: int}
     */
    public function registerCategoryPlayers(Competition $competition, string $category): array
    {
        $registerPlayer = app(RegisterPlayerToCompetitionAction::class);
        $playerNames = FriendlyTournamentRoster::PLAYERS_BY_CATEGORY[$category];

        if ($this->playersByFullName === null) {
            $this->findOrCreatePlayersFromRoster();
        }

        $created = 0;
        $reused = 0;

        foreach ($playerNames as $fullName) {
            $player = $this->playersByFullName[$fullName];

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

    public function finishGame(Game $game, int $scoreVariantIndex = 0): bool
    {
        $game->refresh();

        if ($game->status === GameStatus::Finished) {
            return false;
        }

        $recordGameSet = app(RecordGameSetAction::class);
        $setScores = self::SET_SCORES[$scoreVariantIndex % count(self::SET_SCORES)];

        foreach ($setScores as $setIndex => [$player1Score, $player2Score]) {
            $game = ($recordGameSet)($game, [
                'set_number' => $setIndex + 1,
                'player1_score' => $player1Score,
                'player2_score' => $player2Score,
            ]);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function printScenarioSummary(string $title, array $summary): void
    {
        $this->command?->newLine();
        $this->command?->info('=== '.$title.' ===');

        foreach ($summary as $label => $value) {
            if (is_array($value)) {
                continue;
            }

            $this->command?->line(sprintf('%s: %s', $label, $value));
        }
    }

    /**
     * @return array{0: Group, 1: bool}
     */
    private function findOrCreateGroup(
        Competition $competition,
        string $groupName,
        CreateGroupAction $createGroup,
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

    private function assignPlayerToGroupIfNeeded(
        Competition $competition,
        Group $group,
        Player $player,
        AssignPlayerToGroupAction $assignPlayer,
    ): bool {
        $alreadyAssignedToThisGroup = GroupPlayer::query()
            ->where('group_id', $group->id)
            ->where('player_id', $player->id)
            ->exists();

        if ($alreadyAssignedToThisGroup) {
            return false;
        }

        $alreadyAssignedInCompetition = GroupPlayer::query()
            ->where('player_id', $player->id)
            ->whereHas('group', fn ($query) => $query->where('competition_id', $competition->id))
            ->exists();

        if ($alreadyAssignedInCompetition) {
            return false;
        }

        ($assignPlayer)([
            'group_id' => $group->id,
            'player_id' => $player->id,
        ]);

        return true;
    }

    /**
     * @return array<int, int>
     */
    private function calculateBalancedGroupSizes(int $playerCount, int $groupsCount): array
    {
        $baseSize = intdiv($playerCount, $groupsCount);
        $remainder = $playerCount % $groupsCount;
        $sizes = [];

        for ($index = 0; $index < $groupsCount; $index++) {
            $sizes[] = $baseSize + ($index < $remainder ? 1 : 0);
        }

        return $sizes;
    }

    private function groupNameForIndex(int $index): string
    {
        $groupNumber = $index + 1;

        if ($groupNumber <= 26) {
            return sprintf('Grupo %s', chr(64 + $groupNumber));
        }

        return sprintf('Grupo %d', $groupNumber);
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
}
