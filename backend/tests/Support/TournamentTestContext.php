<?php

namespace Tests\Support;

use App\Actions\Game\CreateGameAction;
use App\Actions\Group\PersistGroupEntryAction;
use App\Actions\CompetitionEntry\PersistCompetitionEntryAction;
use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use App\Enums\TeamTieModality;
use App\Enums\TournamentStatus;
use App\Models\Bracket;
use App\Models\Category;
use App\Models\Competition;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Group;
use App\Models\TeamTie;
use App\Models\TeamTieFormat;
use App\Models\TeamTieFormatSlot;
use App\Support\Competition\ResolveSinglesEntryForPlayer;
use App\Models\Player;
use App\Models\CompetitionEntry;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;

final class TournamentTestContext
{
    public function __construct(
        private readonly TestCase $test,
    ) {}

    public function apiUrl(string $path): string
    {
        return '/api/v1/'.ltrim($path, '/');
    }

    public function createCompetition(
        int $setsToWin = 1,
        int $pointsPerSet = 11,
        CompetitionFormat $format = CompetitionFormat::GroupsKnockout,
    ): Competition {
        return $this->createTypedCompetition(
            CompetitionType::Singles,
            'Singles Test',
            $setsToWin,
            $pointsPerSet,
            $format,
        );
    }

    public function createDoublesCompetition(
        int $setsToWin = 1,
        int $pointsPerSet = 11,
        CompetitionFormat $format = CompetitionFormat::GroupsKnockout,
    ): Competition {
        return $this->createTypedCompetition(
            CompetitionType::Doubles,
            'Doubles Test',
            $setsToWin,
            $pointsPerSet,
            $format,
        );
    }

    private function createTypedCompetition(
        CompetitionType $type,
        string $name,
        int $setsToWin,
        int $pointsPerSet,
        CompetitionFormat $format,
        ?int $teamSize = null,
        ?int $teamTieFormatId = null,
    ): Competition {
        $bestOf = max(1, ($setsToWin * 2) - 1);

        $tournament = Tournament::query()->create([
            'name' => 'Torneo Test',
            'location' => 'Club Test',
            'start_date' => Carbon::today()->toDateString(),
            'status' => TournamentStatus::Draft,
        ]);

        if ($type === CompetitionType::Team && $teamTieFormatId === null) {
            $teamTieFormatId = $this->createTeamTieFormat()->id;
        }

        return Competition::query()->create([
            'tournament_id' => $tournament->id,
            'name' => $name,
            'type' => $type,
            'team_size' => $teamSize,
            'team_tie_format_id' => $type === CompetitionType::Team ? $teamTieFormatId : null,
            'category' => 'primera',
            'category_id' => Category::query()->where('slug', 'primera')->value('id'),
            'format' => $format,
            'sets_to_win' => $setsToWin,
            'points_per_set' => $pointsPerSet,
            'group_stage_best_of' => $bestOf,
            'knockout_stage_best_of' => $bestOf,
            'semifinal_best_of' => $bestOf,
            'final_best_of' => $bestOf,
        ]);
    }

    public function createTeamTieFormat(
        string $name = 'Copa 5',
        int $victoriesRequired = 3,
    ): TeamTieFormat {
        $existing = TeamTieFormat::query()->where('name', $name)->first();

        if ($existing !== null) {
            $existing->load(['slots' => fn ($query) => $query->orderBy('slot_order')]);

            return $existing;
        }

        $format = TeamTieFormat::query()->create([
            'name' => $name,
            'description' => 'Formato de prueba',
            'victories_required' => $victoriesRequired,
            'active' => true,
        ]);

        $modalities = [
            TeamTieModality::Singles,
            TeamTieModality::Singles,
            TeamTieModality::Doubles,
            TeamTieModality::Singles,
            TeamTieModality::Singles,
        ];

        foreach ($modalities as $index => $modality) {
            TeamTieFormatSlot::query()->create([
                'team_tie_format_id' => $format->id,
                'slot_order' => $index + 1,
                'modality' => $modality,
            ]);
        }

        return $format->fresh(['slots']);
    }

    public function createTeamCompetition(
        int $teamSize = 4,
        int $setsToWin = 1,
        int $pointsPerSet = 11,
        CompetitionFormat $format = CompetitionFormat::GroupsKnockout,
    ): Competition {
        return $this->createTypedCompetition(
            CompetitionType::Team,
            'Team Test',
            $setsToWin,
            $pointsPerSet,
            $format,
            $teamSize,
        );
    }

    public function createKnockoutDirectCompetition(
        int $setsToWin = 1,
        int $pointsPerSet = 11,
    ): Competition {
        return $this->createCompetition($setsToWin, $pointsPerSet, CompetitionFormat::KnockoutDirect);
    }

    public function createDoublesKnockoutDirectCompetition(
        int $setsToWin = 1,
        int $pointsPerSet = 11,
    ): Competition {
        return $this->createDoublesCompetition($setsToWin, $pointsPerSet, CompetitionFormat::KnockoutDirect);
    }

    /**
     * @return array<int, Player>
     */
    public function createPlayers(int $count): array
    {
        $players = [];

        for ($index = 1; $index <= $count; $index++) {
            $players[] = Player::query()->create([
                'first_name' => "Jugador{$index}",
                'last_name' => 'Test',
            ]);
        }

        return $players;
    }

    public function registerPlayer(Competition $competition, Player $player): CompetitionEntry
    {
        $persistCompetitionEntry = app(PersistCompetitionEntryAction::class);

        return $persistCompetitionEntry([
            'competition_id' => $competition->id,
            'player_id' => $player->id,
        ]);
    }

    public function registerPair(
        Competition $competition,
        Player $player1,
        Player $player2,
    ): CompetitionEntry {
        $persistCompetitionEntry = app(PersistCompetitionEntryAction::class);

        return $persistCompetitionEntry([
            'competition_id' => $competition->id,
            'player_ids' => [$player1->id, $player2->id],
        ]);
    }

    public function registerPlayerViaApi(
        Competition $competition,
        Player $player,
        array $roles = ['organizer'],
    ): TestResponse {
        return $this->test->postJson($this->apiUrl("competitions/{$competition->id}/registrations"), [
            'player_id' => $player->id,
        ], $this->authHeaders($roles));
    }

    public function registerPairViaApi(
        Competition $competition,
        Player $player1,
        Player $player2,
        array $roles = ['organizer'],
    ): TestResponse {
        return $this->test->postJson($this->apiUrl("competitions/{$competition->id}/registrations"), [
            'player_ids' => [$player1->id, $player2->id],
        ], $this->authHeaders($roles));
    }

    /**
     * @param  list<int>  $playerIds
     */
    public function registerTeam(
        Competition $competition,
        string $name,
        array $playerIds,
    ): CompetitionEntry {
        $persistCompetitionEntry = app(PersistCompetitionEntryAction::class);

        return $persistCompetitionEntry([
            'competition_id' => $competition->id,
            'name' => $name,
            'player_ids' => $playerIds,
        ]);
    }

    /**
     * @param  list<int>  $playerIds
     */
    public function registerTeamViaApi(
        Competition $competition,
        string $name,
        array $playerIds,
        array $roles = ['organizer'],
    ): TestResponse {
        return $this->test->postJson($this->apiUrl("competitions/{$competition->id}/registrations"), [
            'name' => $name,
            'player_ids' => $playerIds,
        ], $this->authHeaders($roles));
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, string>
     */
    protected function authHeaders(array $roles = ['organizer']): array
    {
        if (! method_exists($this->test, 'keycloakAuthHeaders')) {
            return [];
        }

        /** @var callable(array): array<string, string> $resolver */
        $resolver = [$this->test, 'keycloakAuthHeaders'];

        return $resolver($roles);
    }

    /**
     * @param  array<int, Player>  $players
     */
    public function registerPlayers(Competition $competition, array $players): void
    {
        foreach ($players as $player) {
            $this->registerPlayer($competition, $player);
        }
    }

    public function createGroup(Competition $competition, string $name = 'Grupo A'): Group
    {
        return Group::query()->create([
            'competition_id' => $competition->id,
            'name' => $name,
        ]);
    }

    /**
     * @param  array<int, Player>  $players
     */
    public function assignPlayersToGroup(Group $group, array $players): void
    {
        $group->loadMissing('competition');
        $resolveEntry = app(ResolveSinglesEntryForPlayer::class);
        $persistGroupEntry = app(PersistGroupEntryAction::class);

        foreach ($players as $player) {
            $entry = ($resolveEntry)($group->competition, $player->id);
            ($persistGroupEntry)($group, $entry);
        }
    }

    /**
     * @param  array<int, Player>  $players
     */
    public function createGroupWithPlayers(
        Competition $competition,
        array $players,
        string $name = 'Grupo A',
    ): Group {
        $group = $this->createGroup($competition, $name);
        $this->assignPlayersToGroup($group, $players);

        return $group;
    }

    public function createManualGame(Competition $competition, Player $playerOne, Player $playerTwo): Game
    {
        $response = $this->test->postJson($this->apiUrl("competitions/{$competition->id}/games"), [
            'player1_id' => $playerOne->id,
            'player2_id' => $playerTwo->id,
        ], $this->authHeaders(['organizer']));

        $response->assertCreated();

        return Game::query()->findOrFail($response->json('data.id'));
    }

    public function createManualDoublesGame(
        Competition $competition,
        CompetitionEntry $entryOne,
        CompetitionEntry $entryTwo,
    ): Game {
        $response = $this->test->postJson($this->apiUrl("competitions/{$competition->id}/games"), [
            'entry1_id' => $entryOne->id,
            'entry2_id' => $entryTwo->id,
        ], $this->authHeaders(['organizer']));

        $response->assertCreated();

        return Game::query()->findOrFail($response->json('data.id'));
    }

    /**
     * @return array{
     *     competition: Competition,
     *     entries: array<int, CompetitionEntry>,
     *     game: Game,
     * }
     */
    public function createPendingDoublesGame(int $setsToWin = 1, int $pointsPerSet = 11): array
    {
        $competition = $this->createDoublesCompetition($setsToWin, $pointsPerSet);
        $players = $this->createPlayers(4);
        $entries = $this->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
        ]);
        $game = $this->createManualDoublesGame($competition, $entries[0], $entries[1]);

        return [
            'competition' => $competition,
            'entries' => $entries,
            'game' => $game,
        ];
    }

    public function finishGameByEntryViaApi(
        Game $game,
        int $winnerEntryId,
        ?int $pointsPerSet = null,
        array $roles = ['organizer'],
    ): TestResponse {
        $game->loadMissing('competition');
        $pointsPerSet ??= (int) $game->competition->points_per_set;
        $setsToWin = (int) ($game->sets_to_win ?? $game->competition->sets_to_win);
        $entry1Wins = (int) $game->entry1_id === $winnerEntryId;

        $response = null;

        for ($setNumber = 1; $setNumber <= $setsToWin; $setNumber++) {
            $game->refresh();
            $player1Score = $entry1Wins ? $pointsPerSet : 0;
            $player2Score = $entry1Wins ? 0 : $pointsPerSet;

            $response = $this->recordSet($game, $setNumber, $player1Score, $player2Score, $roles);
        }

        return $response ?? $this->recordSet($game, 1, $pointsPerSet, 0, $roles);
    }

    public function recordSet(
        Game $game,
        int $setNumber,
        int $player1Score,
        int $player2Score,
        array $roles = ['organizer'],
    ): TestResponse {
        return $this->test->postJson($this->apiUrl("games/{$game->id}/sets"), [
            'set_number' => $setNumber,
            'player1_score' => $player1Score,
            'player2_score' => $player2Score,
        ], $this->authHeaders($roles));
    }

    /**
     * @param  array<int, array{player1_score: int, player2_score: int}>  $sets
     * @param  list<string>  $roles
     */
    public function correctResult(
        Game $game,
        string $reason,
        array $sets,
        array $roles = ['admin'],
    ): TestResponse {
        return $this->test->postJson($this->apiUrl("games/{$game->id}/corrections"), [
            'reason' => $reason,
            'sets' => $sets,
        ], $this->authHeaders($roles));
    }

    /**
     * @return array{
     *     competition: Competition,
     *     playerOne: Player,
     *     playerTwo: Player,
     *     game: Game,
     * }
     */
    public function createPendingSinglesGame(int $setsToWin = 2, int $pointsPerSet = 11): array
    {
        $competition = $this->createCompetition($setsToWin, $pointsPerSet);
        $players = $this->createPlayers(2);
        $this->registerPlayers($competition, $players);
        [$playerOne, $playerTwo] = $players;
        $game = $this->createManualGame($competition, $playerOne, $playerTwo);

        return [
            'competition' => $competition,
            'playerOne' => $playerOne,
            'playerTwo' => $playerTwo,
            'game' => $game,
        ];
    }

    public function finishGame(Game $game, Player $winner, ?int $pointsPerSet = null): TestResponse
    {
        $game->loadMissing('competition');
        $pointsPerSet ??= (int) $game->competition->points_per_set;
        $setsToWin = (int) ($game->sets_to_win ?? $game->competition->sets_to_win);
        $game->loadMissing(['entry1.members', 'entry2.members']);

        $response = null;

        for ($setNumber = 1; $setNumber <= $setsToWin; $setNumber++) {
            $game->refresh();
            $game->loadMissing(['entry1.members', 'entry2.members']);

            $player1Score = (int) $game->singlesPlayer1Id() === $winner->id ? $pointsPerSet : 0;
            $player2Score = (int) $game->singlesPlayer2Id() === $winner->id ? $pointsPerSet : 0;

            $response = $this->test->postJson($this->apiUrl("games/{$game->id}/sets"), [
                'set_number' => $setNumber,
                'player1_score' => $player1Score,
                'player2_score' => $player2Score,
            ], $this->authHeaders(['organizer']));
        }

        return $response ?? $this->test->postJson($this->apiUrl("games/{$game->id}/sets"), [
            'set_number' => 1,
            'player1_score' => $pointsPerSet,
            'player2_score' => 0,
        ], $this->authHeaders(['organizer']));
    }

    public function generateRoundRobin(Group $group, array $roles = ['organizer']): TestResponse
    {
        return $this->test->postJson(
            $this->apiUrl("groups/{$group->id}/round-robin-games"),
            [],
            $this->authHeaders($roles),
        );
    }

    public function generateTeamRoundRobin(Group $group, array $roles = ['organizer']): TestResponse
    {
        return $this->generateRoundRobin($group, $roles);
    }

    public function listGroupTeamTies(Group $group, array $roles = ['organizer']): TestResponse
    {
        return $this->test->getJson(
            $this->apiUrl("groups/{$group->id}/team-ties"),
            $this->authHeaders($roles),
        );
    }

    /**
     * @param  list<CompetitionEntry>  $entries
     */
    public function createGroupWithEntries(
        Competition $competition,
        array $entries,
        string $name = 'Grupo A',
    ): Group {
        $group = $this->createGroup($competition, $name);

        foreach ($entries as $entry) {
            $this->assignEntryToGroupViaApi($group, $entry)->assertCreated();
        }

        return $group->fresh();
    }

    /**
     * @return list<CompetitionEntry>
     */
    public function registerTeams(
        Competition $competition,
        int $teamCount,
        int $playersPerTeam,
    ): array {
        $entries = [];

        for ($teamIndex = 1; $teamIndex <= $teamCount; $teamIndex++) {
            $players = $this->createPlayers($playersPerTeam);
            $response = $this->registerTeamViaApi(
                $competition,
                "Equipo {$teamIndex}",
                array_map(fn (Player $player): int => $player->id, $players),
            );

            $response->assertCreated();
            $entries[] = CompetitionEntry::query()->findOrFail($response->json('data.id'));
        }

        return $entries;
    }

    public function generateRandomGroups(
        Competition $competition,
        int $groupsCount,
        array $roles = ['organizer'],
    ): TestResponse {
        return $this->test->postJson(
            $this->apiUrl("competitions/{$competition->id}/groups/random-generate"),
            ['groups_count' => $groupsCount],
            $this->authHeaders($roles),
        );
    }

    public function regenerateRandomGroups(
        Competition $competition,
        int $groupsCount,
        array $roles = ['organizer'],
    ): TestResponse {
        return $this->test->postJson(
            $this->apiUrl("competitions/{$competition->id}/groups/regenerate-random"),
            ['groups_count' => $groupsCount],
            $this->authHeaders($roles),
        );
    }

    public function createBracket(
        Competition $competition,
        ?int $qualifiedPerGroup = null,
        array $roles = ['organizer'],
    ): TestResponse {
        if ($qualifiedPerGroup !== null) {
            $competition->update(['qualified_per_group' => $qualifiedPerGroup]);
            $competition->refresh();
        }

        return $this->test->postJson(
            $this->apiUrl("competitions/{$competition->id}/bracket"),
            [],
            $this->authHeaders($roles),
        );
    }

    public function showBracket(Competition $competition): TestResponse
    {
        return $this->test->getJson($this->apiUrl("competitions/{$competition->id}/bracket"));
    }

    public function createCompetitionViaApi(
        int $tournamentId,
        array $overrides = [],
    ): TestResponse {
        return $this->test->postJson($this->apiUrl("tournaments/{$tournamentId}/competitions"), [
            'name' => 'Singles Test',
            'category_id' => Category::query()->where('slug', 'primera')->value('id'),
            'type' => 'singles',
            'format' => 'groups_knockout',
            'points_per_set' => 11,
            ...$overrides,
        ], $this->authHeaders(['organizer']));
    }

    public function updateCompetitionViaApi(Competition $competition, array $payload): TestResponse
    {
        return $this->test->putJson($this->apiUrl("competitions/{$competition->id}"), $payload, $this->authHeaders(['organizer']));
    }

    public function generateBracketNextRound(Bracket $bracket, array $roles = ['organizer']): TestResponse
    {
        return $this->test->postJson(
            $this->apiUrl("brackets/{$bracket->id}/next-round"),
            [],
            $this->authHeaders($roles),
        );
    }

    public function deleteGame(Game $game, array $roles = ['organizer']): TestResponse
    {
        return $this->test->deleteJson(
            $this->apiUrl("games/{$game->id}"),
            [],
            $this->authHeaders($roles),
        );
    }

    public function createGroupViaApi(
        Competition $competition,
        string $name = 'Grupo A',
        array $roles = ['organizer'],
    ): TestResponse {
        return $this->test->postJson(
            $this->apiUrl("competitions/{$competition->id}/groups"),
            ['name' => $name],
            $this->authHeaders($roles),
        );
    }

    public function assignPlayerToGroupViaApi(
        Group $group,
        Player $player,
        array $roles = ['organizer'],
    ): TestResponse {
        return $this->test->postJson(
            $this->apiUrl("groups/{$group->id}/players"),
            ['player_id' => $player->id],
            $this->authHeaders($roles),
        );
    }

    public function assignEntryToGroupViaApi(
        Group $group,
        CompetitionEntry $entry,
        array $roles = ['organizer'],
    ): TestResponse {
        return $this->test->postJson(
            $this->apiUrl("groups/{$group->id}/players"),
            ['competition_entry_id' => $entry->id],
            $this->authHeaders($roles),
        );
    }

    /**
     * @param  array<int, array{Player, Player}>  $pairs
     * @return array<int, CompetitionEntry>
     */
    public function registerPairs(Competition $competition, array $pairs): array
    {
        $entries = [];

        foreach ($pairs as $pair) {
            [$player1, $player2] = $pair;
            $entries[] = $this->registerPair($competition, $player1, $player2);
        }

        return $entries;
    }

    public function finishGameByEntry(Game $game, int $winnerEntryId): void
    {
        $game->update([
            'status' => GameStatus::Finished,
            'winner_entry_id' => $winnerEntryId,
            'finished_at' => now(),
        ]);
    }

    /**
     * @param  iterable<int, Game>  $games
     */
    public function findGameBetweenEntries(iterable $games, int $entry1Id, int $entry2Id): Game
    {
        foreach ($games as $game) {
            $left = (int) $game->entry1_id;
            $right = (int) $game->entry2_id;

            if (
                ($left === $entry1Id && $right === $entry2Id)
                || ($left === $entry2Id && $right === $entry1Id)
            ) {
                return $game;
            }
        }

        throw new \RuntimeException('Game not found between entries.');
    }

    /**
     * @return Collection<int, Game>
     */
    public function bracketGamesForRound(Bracket $bracket, int $round): Collection
    {
        return Game::query()
            ->where('bracket_id', $bracket->id)
            ->mainBracket()
            ->where('bracket_round', $round)
            ->orderBy('bracket_match')
            ->get();
    }

    public function entryIdFor(Competition $competition, Player|int $player): int
    {
        $playerId = $player instanceof Player ? (int) $player->id : $player;

        return app(ResolveSinglesEntryForPlayer::class)($competition, $playerId)->id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function persistGame(Competition $competition, Player $playerOne, Player $playerTwo, array $overrides = []): Game
    {
        return app(CreateGameAction::class)([
            'competition_id' => $competition->id,
            'entry1_id' => $this->entryIdFor($competition, $playerOne),
            'entry2_id' => $this->entryIdFor($competition, $playerTwo),
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function persistByeGame(Competition $competition, Player $player, array $overrides = []): Game
    {
        $entryId = $this->entryIdFor($competition, $player);

        return app(CreateGameAction::class)([
            'competition_id' => $competition->id,
            'entry1_id' => $entryId,
            'entry2_id' => null,
            'winner_entry_id' => $entryId,
            'is_bye' => true,
            ...$overrides,
        ]);
    }

    public function persistByeGameForEntry(Competition $competition, CompetitionEntry $entry, array $overrides = []): Game
    {
        return app(CreateGameAction::class)([
            'competition_id' => $competition->id,
            'entry1_id' => $entry->id,
            'entry2_id' => null,
            'winner_entry_id' => $entry->id,
            'is_bye' => true,
            ...$overrides,
        ]);
    }

    /**
     * @param  iterable<int, Game>  $games
     */
    public function findGameBetween(iterable $games, Player $left, Player $right): Game
    {
        foreach ($games as $game) {
            $game->loadMissing(['entry1.members', 'entry2.members']);
            $sideOne = $game->singlesPlayer1Id();
            $sideTwo = $game->singlesPlayer2Id();

            if (
                ($sideOne === $left->id && $sideTwo === $right->id)
                || ($sideOne === $right->id && $sideTwo === $left->id)
            ) {
                return $game;
            }
        }

        $this->test->fail(sprintf(
            'No se encontró partido entre el jugador %d y el jugador %d.',
            $left->id,
            $right->id,
        ));
    }

    /**
     * @return array{
     *     competition: Competition,
     *     groupA: Group,
     *     groupB: Group,
     *     playerOne: Player,
     *     playerTwo: Player,
     *     playerThree: Player,
     *     playerFour: Player,
     * }
     */
    public function createFourQualifierGroupPhase(bool $finishGroupGames = true): array
    {
        $competition = $this->createCompetition();
        $players = $this->createPlayers(4);
        $this->registerPlayers($competition, $players);
        [$playerOne, $playerTwo, $playerThree, $playerFour] = $players;

        $groupA = $this->createGroupWithPlayers($competition, [$playerOne, $playerTwo], 'Grupo A');
        $groupB = $this->createGroupWithPlayers($competition, [$playerThree, $playerFour], 'Grupo B');

        $this->generateRoundRobin($groupA)->assertCreated();
        $this->generateRoundRobin($groupB)->assertCreated();

        if ($finishGroupGames) {
            $groupAGame = Game::query()->where('group_id', $groupA->id)->sole();
            $groupBGame = Game::query()->where('group_id', $groupB->id)->sole();

            $this->finishGame($groupAGame, $playerOne)->assertOk();
            $this->finishGame($groupBGame, $playerThree)->assertOk();
        }

        return [
            'competition' => $competition,
            'groupA' => $groupA,
            'groupB' => $groupB,
            'playerOne' => $playerOne,
            'playerTwo' => $playerTwo,
            'playerThree' => $playerThree,
            'playerFour' => $playerFour,
        ];
    }

    public function closeTournament(Tournament $tournament, array $roles = ['organizer']): TestResponse
    {
        return $this->test->postJson(
            $this->apiUrl("tournaments/{$tournament->id}/close"),
            [],
            $this->authHeaders($roles),
        );
    }

    /**
     * @return array{champion: Player, runner_up: Player, final: Game}
     */
    public function completeCompetitionThroughFinal(Competition $competition): array
    {
        if (! $competition->brackets()->exists()) {
            $this->createBracket($competition)->assertCreated();
        }

        $bracket = $competition->fresh()->brackets()->firstOrFail();

        while (true) {
            $bracket->refresh();
            $currentRound = (int) Game::query()
                ->where('bracket_id', $bracket->id)
                ->mainBracket()
                ->max('bracket_round');

            $currentGames = $this->bracketGamesForRound($bracket, $currentRound);

            foreach ($currentGames as $game) {
                if ($game->is_bye || $game->status === \App\Enums\GameStatus::Finished) {
                    continue;
                }

                $this->finishGame($game, $game->singlesPlayer1())->assertOk();
            }

            $final = $currentGames->first(fn (Game $game): bool => $game->round === 'Final');

            if ($final !== null) {
                $final = $final->fresh(Game::DISPLAY_RELATIONS);

                return [
                    'champion' => $final->singlesWinner() ?? $final->singlesPlayer1(),
                    'runner_up' => (int) $final->winner_entry_id === (int) $final->entry1_id
                        ? $final->singlesPlayer2()
                        : $final->singlesPlayer1(),
                    'final' => $final,
                ];
            }

            $this->generateBracketNextRound($bracket)->assertCreated();
        }
    }

    /**
     * @return array{champion_entry: CompetitionEntry, runner_up_entry: CompetitionEntry, final: Game}
     */
    public function completeDoublesCompetitionThroughFinal(
        Competition $competition,
        bool $finishThirdPlace = true,
    ): array {
        if (! $competition->brackets()->exists()) {
            $this->createBracket($competition)->assertCreated();
        }

        $bracket = $competition->fresh()->brackets()->firstOrFail();

        while (true) {
            $bracket->refresh();
            $currentRound = (int) Game::query()
                ->where('bracket_id', $bracket->id)
                ->mainBracket()
                ->max('bracket_round');

            $currentGames = $this->bracketGamesForRound($bracket, $currentRound);

            foreach ($currentGames as $game) {
                if ($game->is_bye || $game->status === \App\Enums\GameStatus::Finished) {
                    continue;
                }

                $this->finishGameByEntryViaApi($game, (int) $game->entry1_id)->assertOk();
            }

            $final = $currentGames->first(fn (Game $game): bool => $game->round === 'Final');

            if ($final !== null) {
                $final = $final->fresh(Game::DISPLAY_RELATIONS);
                $championEntry = $final->winnerEntry ?? $final->entry1;
                $runnerUpEntry = (int) $final->winner_entry_id === (int) $final->entry1_id
                    ? $final->entry2
                    : $final->entry1;

                if ($finishThirdPlace) {
                    $thirdPlaceGame = Game::query()
                        ->where('bracket_id', $bracket->id)
                        ->where('bracket_purpose', \App\Enums\BracketGamePurpose::ThirdPlace)
                        ->first();

                    if (
                        $thirdPlaceGame !== null
                        && $thirdPlaceGame->status !== \App\Enums\GameStatus::Finished
                        && $thirdPlaceGame->entry1_id !== null
                    ) {
                        $this->finishGameByEntryViaApi($thirdPlaceGame, (int) $thirdPlaceGame->entry1_id)->assertOk();
                    }
                }

                return [
                    'champion_entry' => $championEntry,
                    'runner_up_entry' => $runnerUpEntry,
                    'final' => $final,
                ];
            }

            $this->generateBracketNextRound($bracket)->assertCreated();
        }
    }

    public function createTournament(array $overrides = []): Tournament
    {
        return Tournament::query()->create([
            'name' => 'Torneo Test',
            'location' => 'Club Test',
            'start_date' => Carbon::today()->toDateString(),
            'status' => TournamentStatus::InProgress,
            ...$overrides,
        ]);
    }
}
