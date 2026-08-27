<?php

namespace Tests\Support;

use App\Actions\Game\CreateGameAction;
use App\Actions\Group\PersistGroupEntryAction;
use App\Actions\Registration\PersistRegistrationAction;
use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use App\Enums\TournamentStatus;
use App\Models\Bracket;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Group;
use App\Support\Competition\ResolveSinglesEntryForPlayer;
use App\Models\Player;
use App\Models\Registration;
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
        $bestOf = max(1, ($setsToWin * 2) - 1);

        $tournament = Tournament::query()->create([
            'name' => 'Torneo Test',
            'location' => 'Club Test',
            'start_date' => Carbon::today()->toDateString(),
            'status' => TournamentStatus::Draft,
        ]);

        return Competition::query()->create([
            'tournament_id' => $tournament->id,
            'name' => 'Singles Test',
            'type' => CompetitionType::Singles,
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

    public function createKnockoutDirectCompetition(
        int $setsToWin = 1,
        int $pointsPerSet = 11,
    ): Competition {
        return $this->createCompetition($setsToWin, $pointsPerSet, CompetitionFormat::KnockoutDirect);
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

    public function registerPlayer(Competition $competition, Player $player): Registration
    {
        $persistRegistration = app(PersistRegistrationAction::class);

        return $persistRegistration([
            'competition_id' => $competition->id,
            'player_id' => $player->id,
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
