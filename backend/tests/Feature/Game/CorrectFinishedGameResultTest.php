<?php

namespace Tests\Feature\Game;

use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Models\Game;
use App\Models\GameSet;
use App\Models\GroupManualTiebreak;
use App\Models\GroupManualTiebreakPlayer;
use App\Models\Player;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class CorrectFinishedGameResultTest extends TestCase
{
    private const REASON = 'El árbitro informó que el marcador del segundo set fue cargado incorrectamente.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
        $this->withHeaders($this->authHeaders(['admin']));
    }

    protected function tearDown(): void
    {
        $this->resetKeycloakClock();

        parent::tearDown();
    }

    public function test_replaces_all_sets_and_recalculates_winner(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 2, pointsPerSet: 11);

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 5)->assertOk();
        $context->recordSet($setup['game'], setNumber: 2, player1Score: 11, player2Score: 6)->assertOk();

        $oldSetIds = GameSet::query()->where('game_id', $setup['game']->id)->pluck('id')->all();
        $oldFinishedAt = $setup['game']->fresh()->finished_at;

        Carbon::setTestNow(Carbon::parse('2026-07-17 16:00:00'));

        $response = $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 8, 'player2_score' => 11],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.status', GameStatus::Finished->value)
            ->assertJsonPath('data.winner_id', $setup['playerOne']->id)
            ->assertJsonPath('data.sets_won.player1', 2)
            ->assertJsonPath('data.sets_won.player2', 1)
            ->assertJsonCount(3, 'data.sets');

        $this->assertDatabaseMissing('game_sets', ['id' => $oldSetIds[0]]);
        $this->assertDatabaseMissing('game_sets', ['id' => $oldSetIds[1]]);

        $newSets = GameSet::query()->where('game_id', $setup['game']->id)->orderBy('set_number')->get();
        $this->assertCount(3, $newSets);
        $this->assertSame([1, 2, 3], $newSets->pluck('set_number')->all());

        $game = Game::query()->findOrFail($setup['game']->id);
        $this->assertSame(GameStatus::Finished, $game->status);
        $this->assertSame($setup['playerOne']->id, $game->winner_id);
        $this->assertTrue($game->finished_at->greaterThan($oldFinishedAt));
    }

    public function test_changes_winner_when_scores_are_corrected(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 2, pointsPerSet: 11);

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 5)->assertOk();
        $context->recordSet($setup['game'], setNumber: 2, player1Score: 11, player2Score: 6)->assertOk();

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 8, 'player2_score' => 11],
                ['player1_score' => 9, 'player2_score' => 11],
            ],
        )->assertOk()
            ->assertJsonPath('data.winner_id', $setup['playerTwo']->id);
    }

    public function test_requires_reason(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSimpleFinishedGame($context);

        $this->postJson($context->apiUrl("games/{$setup['game']->id}/corrections"), [
            'sets' => [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        ], $this->authHeaders(['admin']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_rejects_short_reason(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSimpleFinishedGame($context);

        $context->correctResult(
            $setup['game']->fresh(),
            'Corto',
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_rejects_empty_sets(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSimpleFinishedGame($context);

        $context->correctResult($setup['game']->fresh(), self::REASON, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sets']);
    }

    public function test_rejects_tied_set(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSimpleFinishedGame($context);

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 10, 'player2_score' => 10],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['sets.0.player1_score']);
    }

    public function test_rejects_invalid_final_score(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSimpleFinishedGame($context);

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 10],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['sets.0.player1_score']);
    }

    public function test_rejects_more_sets_than_best_of(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 2, pointsPerSet: 11);
        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 5)->assertOk();
        $context->recordSet($setup['game'], setNumber: 2, player1Score: 11, player2Score: 6)->assertOk();

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 8, 'player2_score' => 11],
                ['player1_score' => 11, 'player2_score' => 7],
                ['player1_score' => 11, 'player2_score' => 5],
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['sets']);
    }

    public function test_rejects_when_no_player_reaches_sets_to_win(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSimpleFinishedGame($context);

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['sets']);
    }

    public function test_rejects_sets_after_decisive_set(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSimpleFinishedGame($context);

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
                ['player1_score' => 11, 'player2_score' => 5],
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['sets']);
    }

    public function test_rejects_non_finished_game(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 2, pointsPerSet: 11);
        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 7)->assertOk();

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['game']);
    }

    public function test_rejects_game_without_sets(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 2, pointsPerSet: 11);

        $game = $setup['game'];
        $game->update([
            'status' => GameStatus::Finished,
            'winner_id' => $setup['playerOne']->id,
            'finished_at' => now(),
        ]);

        $context->correctResult(
            $game->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['game']);
    }

    public function test_rejects_bye_game(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 1);

        $byeGame = $setup['game'];
        $byeGame->sets()->delete();
        $byeGame->update([
            'player2_id' => null,
            'winner_id' => $setup['playerOne']->id,
            'status' => GameStatus::Finished,
            'finished_at' => now(),
            'is_bye' => true,
            'best_of' => null,
            'sets_to_win' => null,
        ]);

        $context->correctResult(
            $byeGame->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 0],
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['game']);
    }

    public function test_allows_group_game_correction_before_bracket_and_updates_standings(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition(setsToWin: 2);
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        [$playerOne, $playerTwo] = $players;
        $group = $context->createGroupWithPlayers($competition, $players, 'Grupo A');
        $context->generateRoundRobin($group)->assertCreated();

        $game = Game::query()->where('group_id', $group->id)->sole();
        $context->finishGame($game, $playerOne)->assertOk();

        $this->getJson($context->apiUrl("groups/{$group->id}/standings"))
            ->assertOk()
            ->assertJsonPath('data.0.player_id', $playerOne->id)
            ->assertJsonPath('data.1.player_id', $playerTwo->id);

        $context->correctResult(
            $game->fresh(),
            self::REASON,
            [
                ['player1_score' => 8, 'player2_score' => 11],
                ['player1_score' => 9, 'player2_score' => 11],
            ],
        )->assertOk()
            ->assertJsonPath('data.winner_id', $playerTwo->id);

        $this->getJson($context->apiUrl("groups/{$group->id}/standings"))
            ->assertOk()
            ->assertJsonPath('data.0.player_id', $playerTwo->id)
            ->assertJsonPath('data.1.player_id', $playerOne->id);
    }

    public function test_marks_manual_tiebreak_stale_after_group_correction(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedTripleTie($context);
        $group = $setup['group'];
        [$playerA, $playerB, $playerC] = $setup['players'];

        $staleTiebreak = GroupManualTiebreak::query()->create([
            'group_id' => $group->id,
            'reason' => 'draw',
            'notes' => 'Override viejo',
            'applied_at' => now(),
        ]);

        GroupManualTiebreakPlayer::query()->create([
            'group_manual_tiebreak_id' => $staleTiebreak->id,
            'player_id' => $playerA->id,
            'position' => 1,
        ]);

        GroupManualTiebreakPlayer::query()->create([
            'group_manual_tiebreak_id' => $staleTiebreak->id,
            'player_id' => $playerB->id,
            'position' => 2,
        ]);

        $game = Game::query()
            ->where('group_id', $group->id)
            ->where('status', GameStatus::Finished)
            ->firstOrFail();

        $context->correctResult(
            $game->fresh(),
            self::REASON,
            $this->correctedSetsForGame($game, $playerC),
        )->assertOk();

        $this->getJson($context->apiUrl("groups/{$group->id}/standings"))
            ->assertOk()
            ->assertJsonCount(1, 'meta.stale_manual_tiebreaks');
    }

    public function test_rejects_group_game_when_bracket_exists(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $groupGame = Game::query()->where('group_id', $setup['groupA']->id)->sole();
        $originalSetIds = GameSet::query()->where('game_id', $groupGame->id)->pluck('id')->all();

        $context->createBracket($setup['competition'])->assertCreated();

        $context->correctResult(
            $groupGame->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['game']);

        $this->assertSame($originalSetIds, GameSet::query()->where('game_id', $groupGame->id)->pluck('id')->all());
    }

    public function test_allows_bracket_game_without_later_round(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinal = $context->bracketGamesForRound($bracket, 1)->first();
        $context->finishGame($semifinal, $setup['playerOne'])->assertOk();

        $context->correctResult(
            $semifinal->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
            ],
        )->assertOk()
            ->assertJsonPath('data.winner_id', $setup['playerOne']->id);
    }

    public function test_rejects_bracket_game_when_later_round_exists(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();
        $context->finishGame($semifinals[1], $setup['playerThree'])->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $originalSetIds = GameSet::query()->where('game_id', $semifinals[0]->id)->pluck('id')->all();

        $context->correctResult(
            $semifinals[0]->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['game']);

        $this->assertSame($originalSetIds, GameSet::query()->where('game_id', $semifinals[0]->id)->pluck('id')->all());
    }

    public function test_rejects_when_competition_final_is_finished(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);
        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();
        $context->finishGame($semifinals[1], $setup['playerThree'])->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->sole();
        $context->finishGame($final, $setup['playerOne'])->assertOk();

        $context->correctResult(
            $final->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);
    }

    public function test_failed_correction_does_not_modify_sets_or_audit_log(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSimpleFinishedGame($context);
        $originalSetIds = GameSet::query()->where('game_id', $setup['game']->id)->pluck('id')->sort()->values()->all();
        $auditCount = Activity::query()->count();

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
                ['player1_score' => 11, 'player2_score' => 5],
            ],
        )->assertUnprocessable();

        $this->assertSame(
            $originalSetIds,
            GameSet::query()->where('game_id', $setup['game']->id)->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame($auditCount, Activity::query()->count());
        $this->assertSame($setup['playerOne']->id, $setup['game']->fresh()->winner_id);
    }

    /**
     * @return array{
     *     competition: \App\Models\Competition,
     *     playerOne: Player,
     *     playerTwo: Player,
     *     game: Game,
     * }
     */
    private function createSimpleFinishedGame(TournamentTestContext $context): array
    {
        $setup = $context->createPendingSinglesGame(setsToWin: 2, pointsPerSet: 11);
        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 5)->assertOk();
        $context->recordSet($setup['game'], setNumber: 2, player1Score: 11, player2Score: 6)->assertOk();

        return $setup;
    }

    /**
     * @return array{
     *     competition: \App\Models\Competition,
     *     group: \App\Models\Group,
     *     players: array<int, Player>
     * }
     */
    private function createUnresolvedTripleTie(TournamentTestContext $context): array
    {
        $competition = $context->createCompetition(setsToWin: 3);
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()->where('group_id', $group->id)->get();
        $balancedSets = [
            [11, 9],
            [11, 9],
            [9, 11],
            [11, 9],
        ];

        $this->playMatch($context, $context->findGameBetween($games, $players[0], $players[1]), $players[0], $players[1], $balancedSets);
        $this->playMatch($context, $context->findGameBetween($games, $players[1], $players[2]), $players[1], $players[2], $balancedSets);
        $this->playMatch($context, $context->findGameBetween($games, $players[2], $players[0]), $players[2], $players[0], $balancedSets);

        return [
            'competition' => $competition,
            'group' => $group,
            'players' => $players,
        ];
    }

    /**
     * @return array<int, array{player1_score: int, player2_score: int}>
     */
    private function correctedSetsForGame(Game $game, Player $winner): array
    {
        $game->loadMissing('competition');
        $pointsPerSet = (int) $game->competition->points_per_set;
        $setsToWin = (int) ($game->sets_to_win ?? $game->competition->sets_to_win);
        $sets = [];

        for ($setNumber = 1; $setNumber <= $setsToWin; $setNumber++) {
            $player1Score = (int) $game->player1_id === $winner->id ? $pointsPerSet : 0;
            $player2Score = (int) $game->player2_id === $winner->id ? $pointsPerSet : 0;

            $sets[] = [
                'player1_score' => $player1Score,
                'player2_score' => $player2Score,
            ];
        }

        return $sets;
    }

    /**
     * @param  array<int, array{int, int}>  $sets
     */
    private function playMatch(
        TournamentTestContext $context,
        Game $game,
        Player $leftPlayer,
        Player $rightPlayer,
        array $sets,
    ): void {
        foreach ($sets as $index => [$leftScore, $rightScore]) {
            $player1IsLeft = (int) $game->player1_id === $leftPlayer->id;
            $player1Score = $player1IsLeft ? $leftScore : $rightScore;
            $player2Score = $player1IsLeft ? $rightScore : $leftScore;

            $context->recordSet(
                $game,
                setNumber: $index + 1,
                player1Score: $player1Score,
                player2Score: $player2Score,
            )->assertOk();
        }
    }
}
