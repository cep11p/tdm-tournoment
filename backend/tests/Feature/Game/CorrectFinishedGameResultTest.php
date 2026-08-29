<?php

namespace Tests\Feature\Game;

use App\Enums\GameStatus;
use App\Enums\TournamentStatus;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\Game;
use App\Models\GameSet;
use App\Models\Group;
use App\Models\GroupManualTiebreak;
use App\Models\GroupManualTiebreakEntry;
use App\Models\Player;
use App\Support\Competition\ResolveSinglesEntryForPlayer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

        Carbon::setTestNow(Carbon::parse('2026-07-17 15:00:00'));

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
        $this->assertSame($setup['playerOne']->id, $game->singlesWinnerId());
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
            'winner_entry_id' => $game->entry1_id,
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
            'entry2_id' => null,
            'winner_entry_id' => $byeGame->entry1_id,
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

        $resolveEntry = app(ResolveSinglesEntryForPlayer::class);
        $group->loadMissing('competition');
        $entryA = ($resolveEntry)($group->competition, $playerA->id);
        $entryB = ($resolveEntry)($group->competition, $playerB->id);

        GroupManualTiebreakEntry::query()->create([
            'group_manual_tiebreak_id' => $staleTiebreak->id,
            'competition_entry_id' => $entryA->id,
            'position' => 1,
        ]);

        GroupManualTiebreakEntry::query()->create([
            'group_manual_tiebreak_id' => $staleTiebreak->id,
            'competition_entry_id' => $entryB->id,
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

    public function test_propagates_winner_to_immediate_round_when_destination_is_safe(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createQuarterfinalWithSemifinalPending($context);

        $quarterfinalOne = $setup['quarterfinals'][0]->fresh(Game::DISPLAY_RELATIONS);
        $semifinalOne = $setup['semifinals'][0];
        $newWinner = (int) $quarterfinalOne->singlesWinnerId() === (int) $quarterfinalOne->singlesPlayer1Id()
            ? $quarterfinalOne->singlesPlayer2()
            : $quarterfinalOne->singlesPlayer1();

        $context->correctResult(
            $quarterfinalOne->fresh(),
            self::REASON,
            $this->correctedSetsForGame($quarterfinalOne->fresh(), $newWinner),
        )->assertOk()
            ->assertJsonPath('data.winner_id', $newWinner->id);

        $semifinalOne->refresh();
        $this->assertSame($newWinner->id, $semifinalOne->singlesPlayer1Id());
        $this->assertSame($setup['quarterfinals'][1]->fresh()->singlesWinnerId(), $semifinalOne->singlesPlayer2Id());
    }

    public function test_propagates_to_player2_slot_for_even_quarterfinal_match(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createQuarterfinalWithSemifinalPending($context);

        $quarterfinalTwo = $setup['quarterfinals'][1]->fresh(Game::DISPLAY_RELATIONS);
        $semifinalOne = $setup['semifinals'][0];
        $newWinner = (int) $quarterfinalTwo->singlesWinnerId() === (int) $quarterfinalTwo->singlesPlayer1Id()
            ? $quarterfinalTwo->singlesPlayer2()
            : $quarterfinalTwo->singlesPlayer1();

        $context->correctResult(
            $quarterfinalTwo->fresh(),
            self::REASON,
            $this->correctedSetsForGame($quarterfinalTwo->fresh(), $newWinner),
        )->assertOk()
            ->assertJsonPath('data.winner_id', $newWinner->id);

        $semifinalOne->refresh();
        $this->assertSame($setup['quarterfinals'][0]->fresh()->singlesWinnerId(), $semifinalOne->singlesPlayer1Id());
        $this->assertSame($newWinner->id, $semifinalOne->singlesPlayer2Id());
    }

    public function test_does_not_modify_destination_when_winner_is_unchanged(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createQuarterfinalWithSemifinalPending($context);

        $quarterfinalOne = $setup['quarterfinals'][0]->fresh();
        $semifinalOne = $setup['semifinals'][0]->fresh();
        $before = [
            'player1_id' => $semifinalOne->singlesPlayer1Id(),
            'player2_id' => $semifinalOne->singlesPlayer2Id(),
        ];

        $context->correctResult(
            $quarterfinalOne,
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 8],
            ],
        )->assertOk()
            ->assertJsonPath('data.winner_id', $quarterfinalOne->singlesWinnerId());

        $semifinalOne->refresh();
        $this->assertSame($before['player1_id'], $semifinalOne->singlesPlayer1Id());
        $this->assertSame($before['player2_id'], $semifinalOne->singlesPlayer2Id());
    }

    public function test_propagates_from_semifinal_to_pending_final(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);
        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();
        $context->finishGame($semifinals[1], $setup['playerThree'])->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $semifinalTwo = $semifinals[1]->fresh(Game::DISPLAY_RELATIONS);
        $newWinner = (int) $semifinalTwo->singlesWinnerId() === (int) $semifinalTwo->singlesPlayer1Id()
            ? $semifinalTwo->singlesPlayer2()
            : $semifinalTwo->singlesPlayer1();

        $context->correctResult(
            $semifinalTwo,
            self::REASON,
            $this->correctedSetsForGame($semifinalTwo, $newWinner),
        )->assertOk()
            ->assertJsonPath('data.winner_id', $newWinner->id);

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->sole();
        $final->refresh();
        $this->assertSame($semifinals[0]->fresh()->singlesWinnerId(), $final->singlesPlayer1Id());
        $this->assertSame($newWinner->id, $final->singlesPlayer2Id());
    }

    public function test_allows_propagation_when_destination_opponent_came_from_bye(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition(setsToWin: 2);
        $players = $context->createPlayers(5);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $firstRound = $context->bracketGamesForRound($bracket, 1)->sortBy('bracket_match')->values();
        $realGame = $firstRound->first(fn (Game $game): bool => ! $game->is_bye);
        $byeGames = $firstRound->filter(fn (Game $game): bool => $game->is_bye);

        $this->assertNotNull($realGame);
        $this->assertNotEmpty($byeGames);

        $context->finishGame($realGame, Player::query()->findOrFail($realGame->singlesPlayer1Id()))->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $realGame->refresh();
        $secondRound = $context->bracketGamesForRound($bracket->fresh(), 2);
        $destination = $secondRound->first(
            fn (Game $game): bool => (int) $game->singlesPlayer1Id() === (int) $realGame->singlesWinnerId()
                || (int) $game->singlesPlayer2Id() === (int) $realGame->singlesWinnerId(),
        );

        $this->assertNotNull($destination);

        $newWinner = (int) $realGame->singlesPlayer1Id() === (int) $realGame->singlesWinnerId()
            ? Player::query()->findOrFail($realGame->singlesPlayer2Id())
            : Player::query()->findOrFail($realGame->singlesPlayer1Id());

        $context->correctResult(
            $realGame->fresh(Game::DISPLAY_RELATIONS),
            self::REASON,
            $this->correctedSetsForGame($realGame->fresh(Game::DISPLAY_RELATIONS), $newWinner),
        )->assertOk()
            ->assertJsonPath('data.winner_id', $newWinner->id);

        $destination->refresh();
        $this->assertTrue(
            (int) $destination->singlesPlayer1Id() === $newWinner->id
            || (int) $destination->singlesPlayer2Id() === $newWinner->id,
        );
    }

    public function test_rejects_when_destination_has_sets(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createQuarterfinalWithSemifinalPending($context);

        $quarterfinalOne = $setup['quarterfinals'][0];
        $semifinalOne = $setup['semifinals'][0];
        $originalWinnerId = $quarterfinalOne->fresh()->singlesWinnerId();
        $originalSetIds = GameSet::query()->where('game_id', $quarterfinalOne->id)->pluck('id')->all();
        $originalDestination = $semifinalOne->only(['entry1_id', 'entry2_id', 'winner_entry_id', 'status']);

        $context->recordSet($semifinalOne, setNumber: 1, player1Score: 11, player2Score: 9)->assertOk();
        $semifinalOne->update(['status' => GameStatus::Pending, 'winner_entry_id' => null, 'finished_at' => null]);

        $context->correctResult(
            $quarterfinalOne->fresh(),
            self::REASON,
            $this->correctedSetsForGame(
                $quarterfinalOne->fresh(),
                $quarterfinalOne->singlesPlayer1Id() === $originalWinnerId
                    ? $quarterfinalOne->singlesPlayer2()
                    : $quarterfinalOne->singlesPlayer1(),
            ),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['dependent_game']);

        $this->assertSame($originalSetIds, GameSet::query()->where('game_id', $quarterfinalOne->id)->pluck('id')->all());
        $this->assertSame($originalWinnerId, $quarterfinalOne->fresh()->singlesWinnerId());
        $semifinalOne->refresh();
        $this->assertSame($originalDestination['entry1_id'], $semifinalOne->entry1_id);
        $this->assertSame($originalDestination['entry2_id'], $semifinalOne->entry2_id);
    }

    public function test_rejects_when_destination_is_in_progress(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createQuarterfinalWithSemifinalPending($context);

        $quarterfinalOne = $setup['quarterfinals'][0];
        $semifinalOne = $setup['semifinals'][0];
        $semifinalOne->update(['status' => GameStatus::InProgress]);

        $this->assertCorrectionBlockedWithDestinationIntact($context, $quarterfinalOne, $semifinalOne);
    }

    public function test_rejects_when_destination_is_finished(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createQuarterfinalWithSemifinalPending($context);

        $quarterfinalOne = $setup['quarterfinals'][0];
        $semifinalOne = $setup['semifinals'][0];
        $context->finishGame($semifinalOne, $semifinalOne->singlesPlayer1())->assertOk();

        $this->assertCorrectionBlockedWithDestinationIntact($context, $quarterfinalOne, $semifinalOne->fresh());
    }

    public function test_rejects_when_destination_has_winner_id(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createQuarterfinalWithSemifinalPending($context);

        $quarterfinalOne = $setup['quarterfinals'][0];
        $semifinalOne = $setup['semifinals'][0];
        $semifinalOne->update(['winner_entry_id' => $semifinalOne->entry1_id]);

        $this->assertCorrectionBlockedWithDestinationIntact($context, $quarterfinalOne, $semifinalOne);
    }

    public function test_rejects_when_expected_slot_does_not_contain_old_winner(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createQuarterfinalWithSemifinalPending($context);

        $quarterfinalOne = $setup['quarterfinals'][0];
        $semifinalOne = $setup['semifinals'][0];
        $semifinalOne->update(['entry1_id' => $context->entryIdFor($setup['competition'], $setup['players'][7])]);

        $this->assertCorrectionBlockedWithDestinationIntact($context, $quarterfinalOne, $semifinalOne);
    }

    public function test_rejects_when_new_winner_already_occupies_other_destination_slot(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createQuarterfinalWithSemifinalPending($context);

        $quarterfinalOne = $setup['quarterfinals'][0]->fresh(Game::DISPLAY_RELATIONS);
        $semifinalOne = $setup['semifinals'][0];
        $originalWinnerId = $quarterfinalOne->singlesWinnerId();

        $semifinalOne->update([
            'entry1_id' => $quarterfinalOne->entry1_id,
            'entry2_id' => $quarterfinalOne->entry2_id,
        ]);

        $duplicateWinner = Player::query()->findOrFail($quarterfinalOne->singlesPlayer2Id());

        $context->correctResult(
            $quarterfinalOne,
            self::REASON,
            $this->correctedSetsForGame($quarterfinalOne, $duplicateWinner),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['dependent_game']);

        $this->assertSame($originalWinnerId, $quarterfinalOne->fresh()->singlesWinnerId());
        $semifinalOne->refresh();
        $this->assertSame($quarterfinalOne->singlesPlayer1Id(), $semifinalOne->singlesPlayer1Id());
        $this->assertSame($quarterfinalOne->singlesPlayer2Id(), $semifinalOne->singlesPlayer2Id());
    }

    public function test_rejects_when_round_beyond_immediate_exists(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createQuarterfinalWithSemifinalPending($context);

        $context->persistGame($setup['competition'], $setup['players'][0], $setup['players'][1], [
            'bracket_id' => $setup['bracket']->id,
            'status' => GameStatus::Pending,
            'round' => 'Final',
            'bracket_round' => 3,
            'bracket_match' => 1,
            'is_bye' => false,
            'best_of' => 1,
            'sets_to_win' => 1,
        ]);

        $quarterfinalOne = $setup['quarterfinals'][0];

        $context->correctResult(
            $quarterfinalOne->fresh(),
            self::REASON,
            $this->correctedSetsForGame(
                $quarterfinalOne->fresh(),
                $quarterfinalOne->singlesPlayer1Id() === $quarterfinalOne->singlesWinnerId()
                    ? $quarterfinalOne->singlesPlayer2()
                    : $quarterfinalOne->singlesPlayer1(),
            ),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['game']);
    }

    public function test_rejects_correction_when_tournament_is_closed(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createQuarterfinalWithSemifinalPending($context);

        $tournament = $setup['competition']->tournament;
        $tournament->update([
            'status' => TournamentStatus::Finished,
            'closed_at' => now(),
        ]);

        $quarterfinalOne = $setup['quarterfinals'][0]->fresh(Game::DISPLAY_RELATIONS);
        $semifinalOne = $setup['semifinals'][0];
        $auditCount = Activity::query()->count();
        $expectedSemifinalPlayer1 = $semifinalOne->singlesPlayer1Id();
        $originalWinnerId = $quarterfinalOne->singlesWinnerId();
        $newWinner = (int) $quarterfinalOne->singlesPlayer1Id() === (int) $originalWinnerId
            ? $quarterfinalOne->singlesPlayer2()
            : $quarterfinalOne->singlesPlayer1();

        $context->correctResult(
            $quarterfinalOne,
            self::REASON,
            $this->correctedSetsForGame($quarterfinalOne, $newWinner),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);

        $this->assertSame($originalWinnerId, $quarterfinalOne->fresh()->singlesWinnerId());
        $semifinalOne->refresh();
        $this->assertSame($expectedSemifinalPlayer1, $semifinalOne->singlesPlayer1Id());
        $this->assertSame($auditCount, Activity::query()->count());
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
            $semifinals[0]->fresh(),
            self::REASON,
            [
                ['player1_score' => 0, 'player2_score' => 11],
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
        $this->assertSame($setup['playerOne']->id, $setup['game']->fresh()->singlesWinnerId());
    }

    /**
     * @return array{
     *     competition: Competition,
     *     bracket: Bracket,
     *     players: array<int, Player>,
     *     quarterfinals: Collection<int, Game>,
     *     semifinals: Collection<int, Game>,
     * }
     */
    private function createQuarterfinalWithSemifinalPending(TournamentTestContext $context): array
    {
        $competition = $context->createKnockoutDirectCompetition(setsToWin: 2);
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $quarterfinals = $context->bracketGamesForRound($bracket, 1)->sortBy('bracket_match')->values();

        foreach ($quarterfinals as $quarterfinal) {
            $context->finishGame(
                $quarterfinal,
                Player::query()->findOrFail($quarterfinal->singlesPlayer1Id()),
            )->assertOk();
        }

        $context->generateBracketNextRound($bracket)->assertCreated();

        return [
            'competition' => $competition,
            'bracket' => $bracket->fresh(),
            'players' => $players,
            'quarterfinals' => $quarterfinals,
            'semifinals' => $context->bracketGamesForRound($bracket->fresh(), 2)->sortBy('bracket_match')->values(),
        ];
    }

    private function assertCorrectionBlockedWithDestinationIntact(
        TournamentTestContext $context,
        Game $sourceGame,
        Game $destinationGame,
    ): void {
        $originalWinnerId = $sourceGame->fresh()->singlesWinnerId();
        $originalDestination = $destinationGame->fresh()->only(['entry1_id', 'entry2_id', 'winner_entry_id', 'status']);
        $auditCount = Activity::query()->count();

        $newWinner = (int) $sourceGame->singlesPlayer1Id() === (int) $originalWinnerId
            ? Player::query()->findOrFail($sourceGame->singlesPlayer2Id())
            : Player::query()->findOrFail($sourceGame->singlesPlayer1Id());

        $context->correctResult(
            $sourceGame->fresh(),
            self::REASON,
            $this->correctedSetsForGame($sourceGame->fresh(), $newWinner),
        )->assertUnprocessable();

        $this->assertSame($originalWinnerId, $sourceGame->fresh()->singlesWinnerId());

        $destinationGame->refresh();
        $this->assertSame($originalDestination['entry1_id'], $destinationGame->entry1_id);
        $this->assertSame($originalDestination['entry2_id'], $destinationGame->entry2_id);
        $this->assertSame($originalDestination['winner_entry_id'], $destinationGame->winner_entry_id);
        $this->assertSame(
            $originalDestination['status'] instanceof GameStatus
                ? $originalDestination['status']->value
                : $originalDestination['status'],
            $destinationGame->status->value,
        );
        $this->assertSame($auditCount, Activity::query()->count());
    }

    /**
     * @return array{
     *     competition: Competition,
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
     *     competition: Competition,
     *     group: Group,
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
            $player1Score = (int) $game->singlesPlayer1Id() === $winner->id ? $pointsPerSet : 0;
            $player2Score = (int) $game->singlesPlayer2Id() === $winner->id ? $pointsPerSet : 0;

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
            $player1IsLeft = (int) $game->singlesPlayer1Id() === $leftPlayer->id;
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
