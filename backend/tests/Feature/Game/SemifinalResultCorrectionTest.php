<?php

namespace Tests\Feature\Game;

use App\Enums\AuditAction;
use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Enums\ThirdPlaceMode;
use App\Models\Bracket;
use App\Models\Game;
use App\Models\Player;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SemifinalResultCorrectionTest extends TestCase
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

    public function test_playoff_sf1_correction_propagates_winner_and_loser(): void
    {
        $setup = $this->setupPlayoffAfterSemifinals();
        $context = $setup['context'];

        $sf1 = $this->semifinalByMatch($setup['semifinals'], 1)->fresh([...Game::DISPLAY_RELATIONS, 'competition']);
        $final = $setup['final']->fresh();
        $thirdPlace = $setup['thirdPlace']->fresh();

        $oldWinnerId = (int) $sf1->singlesWinnerId();
        $oldLoserId = $oldWinnerId === (int) $sf1->singlesPlayer1Id() ? (int) $sf1->singlesPlayer2Id() : (int) $sf1->singlesPlayer1Id();
        $newWinnerPlayer = (int) $sf1->singlesPlayer1Id() === $oldWinnerId ? $sf1->singlesPlayer2() : $sf1->singlesPlayer1();

        $this->assertSame($oldWinnerId, $final->singlesPlayer1Id());
        $this->assertSame($oldLoserId, $thirdPlace->singlesPlayer1Id());

        $auditBefore = Activity::query()->where('description', AuditAction::GAME_RESULT_CORRECTED->value)->count();

        $context->correctResult(
            $sf1,
            self::REASON,
            $this->correctedSetsForGame($sf1, $newWinnerPlayer),
        )->assertOk()
            ->assertJsonPath('data.winner_id', $newWinnerPlayer->id);

        $final->refresh();
        $thirdPlace->refresh();
        $this->assertSame($newWinnerPlayer->id, $final->singlesPlayer1Id());
        $this->assertSame($oldWinnerId, $thirdPlace->singlesPlayer1Id());
        $this->assertSame(1, Activity::query()->where('description', AuditAction::GAME_RESULT_CORRECTED->value)->count() - $auditBefore);

        $activity = Activity::query()
            ->where('description', AuditAction::GAME_RESULT_CORRECTED->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue(data_get($activity->properties, 'summary.propagation.winner.applied'));
        $this->assertTrue(data_get($activity->properties, 'summary.propagation.loser.applied'));
        $this->assertSame('entry1_id', data_get($activity->properties, 'summary.propagation.winner.slot'));
        $this->assertSame('entry1_id', data_get($activity->properties, 'summary.propagation.loser.slot'));
    }

    public function test_playoff_sf2_correction_propagates_to_final_player2_and_third_place_player2(): void
    {
        $setup = $this->setupPlayoffAfterSemifinals();
        $context = $setup['context'];
        $players = $setup['players'];

        $sf2 = $this->semifinalByMatch($setup['semifinals'], 2)->fresh([...Game::DISPLAY_RELATIONS, 'competition']);
        $newWinner = (int) $sf2->singlesWinnerId() === (int) $sf2->singlesPlayer1Id() ? $sf2->singlesPlayer2() : $sf2->singlesPlayer1();

        $context->correctResult(
            $sf2,
            self::REASON,
            $this->correctedSetsForGame($sf2, $newWinner),
        )->assertOk();

        $setup['final']->refresh();
        $setup['thirdPlace']->refresh();

        $this->assertSame($newWinner->id, $setup['final']->singlesPlayer2Id());
        $this->assertSame(
            (int) $sf2->singlesPlayer1Id() === (int) $newWinner->id ? $sf2->singlesPlayer2Id() : $sf2->singlesPlayer1Id(),
            $setup['thirdPlace']->singlesPlayer2Id(),
        );
    }

    public function test_rejects_when_final_started(): void
    {
        $setup = $this->setupPlayoffAfterSemifinals();
        $context = $setup['context'];
        $sf1 = $this->semifinalByMatch($setup['semifinals'], 1)->fresh([...Game::DISPLAY_RELATIONS, 'competition']);
        $final = $setup['final'];
        $originalFinal = $final->only(['entry1_id', 'entry2_id', 'status']);
        $originalWinnerId = $sf1->singlesWinnerId();

        $final->update(['status' => GameStatus::InProgress]);

        $newWinner = $sf1->singlesPlayer2();
        $context->correctResult(
            $sf1,
            self::REASON,
            $this->correctedSetsForGame($sf1, $newWinner),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['dependent_game']);

        $final->refresh();
        $this->assertSame($originalFinal['entry1_id'], $final->entry1_id);
        $this->assertSame($originalWinnerId, $sf1->fresh()->singlesWinnerId());
    }

    public function test_rejects_when_third_place_started(): void
    {
        $setup = $this->setupPlayoffAfterSemifinals();
        $context = $setup['context'];
        $sf1 = $this->semifinalByMatch($setup['semifinals'], 1)->fresh([...Game::DISPLAY_RELATIONS, 'competition']);
        $thirdPlace = $setup['thirdPlace'];
        $originalThird = $thirdPlace->only(['entry1_id', 'entry2_id']);

        $context->recordSet($thirdPlace, setNumber: 1, player1Score: 11, player2Score: 7)->assertOk();

        $context->correctResult(
            $sf1,
            self::REASON,
            $this->correctedSetsForGame($sf1, $sf1->singlesPlayer2()),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['dependent_game']);

        $thirdPlace->refresh();
        $this->assertSame($originalThird['entry1_id'], $thirdPlace->entry1_id);
    }

    public function test_rejects_when_final_finished(): void
    {
        $setup = $this->setupPlayoffAfterSemifinals();
        $context = $setup['context'];
        $sf1 = $this->semifinalByMatch($setup['semifinals'], 1)->fresh([...Game::DISPLAY_RELATIONS, 'competition']);

        $context->finishGame($setup['final'], $setup['players'][0])->assertOk();

        $context->correctResult(
            $sf1,
            self::REASON,
            $this->correctedSetsForGame($sf1, $sf1->singlesPlayer2()),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);
    }

    public function test_rejects_when_third_place_finished(): void
    {
        $setup = $this->setupPlayoffAfterSemifinals();
        $context = $setup['context'];
        $sf1 = $this->semifinalByMatch($setup['semifinals'], 1)->fresh([...Game::DISPLAY_RELATIONS, 'competition']);

        $context->finishGame($setup['thirdPlace'], $setup['players'][1])->assertOk();

        $context->correctResult(
            $sf1,
            self::REASON,
            $this->correctedSetsForGame($sf1, $sf1->singlesPlayer2()),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['dependent_game']);
    }

    public function test_rejects_when_both_final_and_third_place_started(): void
    {
        $setup = $this->setupPlayoffAfterSemifinals();
        $context = $setup['context'];
        $sf1 = $this->semifinalByMatch($setup['semifinals'], 1)->fresh([...Game::DISPLAY_RELATIONS, 'competition']);

        $setup['final']->update(['status' => GameStatus::InProgress]);
        $setup['thirdPlace']->update(['status' => GameStatus::InProgress]);

        $response = $context->correctResult(
            $sf1,
            self::REASON,
            $this->correctedSetsForGame($sf1, $sf1->singlesPlayer2()),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['dependent_game']);

        $this->assertStringContainsString(
            'Final y el partido por tercer puesto',
            (string) $response->json('errors.dependent_game.0'),
        );
    }

    public function test_rejects_when_final_slot_inconsistent(): void
    {
        $setup = $this->setupPlayoffAfterSemifinals();
        $context = $setup['context'];
        $sf1 = $this->semifinalByMatch($setup['semifinals'], 1)->fresh([...Game::DISPLAY_RELATIONS, 'competition']);

        $setup['final']->update(['entry1_id' => $context->entryIdFor($setup['competition'], $setup['players'][3])]);

        $context->correctResult(
            $sf1,
            self::REASON,
            $this->correctedSetsForGame($sf1, $sf1->singlesPlayer2()),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['game']);
    }

    public function test_rejects_when_third_place_slot_inconsistent(): void
    {
        $setup = $this->setupPlayoffAfterSemifinals();
        $context = $setup['context'];
        $sf1 = $this->semifinalByMatch($setup['semifinals'], 1)->fresh([...Game::DISPLAY_RELATIONS, 'competition']);

        $setup['thirdPlace']->fresh()->update(['entry1_id' => $context->entryIdFor($setup['competition'], $setup['players'][1])]);

        $context->correctResult(
            $sf1,
            self::REASON,
            $this->correctedSetsForGame($sf1, $sf1->singlesPlayer2()),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['game']);
    }

    public function test_rejects_duplicate_player_in_final(): void
    {
        $setup = $this->setupPlayoffAfterSemifinals();
        $context = $setup['context'];
        $sf1 = $this->semifinalByMatch($setup['semifinals'], 1)->fresh([...Game::DISPLAY_RELATIONS, 'competition']);
        $newWinner = $sf1->singlesPlayer2();

        $setup['final']->update(['entry2_id' => $context->entryIdFor($setup['competition'], $newWinner)]);

        $context->correctResult(
            $sf1,
            self::REASON,
            $this->correctedSetsForGame($sf1, $newWinner),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['dependent_game']);
    }

    public function test_rejects_duplicate_player_in_third_place(): void
    {
        $setup = $this->setupPlayoffAfterSemifinals();
        $context = $setup['context'];
        $sf1 = $this->semifinalByMatch($setup['semifinals'], 1)->fresh([...Game::DISPLAY_RELATIONS, 'competition']);
        $newWinner = $sf1->singlesPlayer2();
        $newLoser = $sf1->singlesPlayer1();

        $setup['thirdPlace']->update(['entry2_id' => $context->entryIdFor($setup['competition'], $newLoser)]);

        $context->correctResult(
            $sf1,
            self::REASON,
            $this->correctedSetsForGame($sf1, $newWinner),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['dependent_game']);
    }

    public function test_rollback_when_third_place_validation_fails(): void
    {
        $setup = $this->setupPlayoffAfterSemifinals();
        $context = $setup['context'];
        $sf1 = $setup['semifinals'][0]->fresh([...Game::DISPLAY_RELATIONS, 'competition', 'sets']);
        $originalWinner = $sf1->singlesWinnerId();
        $originalSetCount = $sf1->sets()->count();

        $setup['thirdPlace']->fresh()->update(['entry1_id' => $context->entryIdFor($setup['competition'], $setup['players'][1])]);

        $context->correctResult(
            $sf1,
            self::REASON,
            $this->correctedSetsForGame($sf1, $sf1->singlesPlayer2()),
        )->assertUnprocessable();

        $sf1->refresh();
        $this->assertSame($originalWinner, $sf1->singlesWinnerId());
        $this->assertSame($originalSetCount, $sf1->sets()->count());
        $this->assertSame($setup['players'][0]->id, $setup['final']->fresh()->singlesPlayer1Id());
    }

    public function test_shared_mode_propagates_winner_only_and_recalculates_shared_third_place(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Shared]);
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1)->sortBy('bracket_match')->values();

        $context->finishGame($this->semifinalByMatch($semifinals, 1), $players[0])->assertOk();
        $context->finishGame($this->semifinalByMatch($semifinals, 2), $players[2])->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->sole();
        $sf1 = $this->semifinalByMatch($semifinals, 1)->fresh([...Game::DISPLAY_RELATIONS, 'competition']);
        $oldSf1WinnerId = (int) $sf1->singlesWinnerId();
        $newWinnerPlayer = (int) $sf1->singlesPlayer1Id() === $oldSf1WinnerId ? $sf1->singlesPlayer2() : $sf1->singlesPlayer1();

        $context->correctResult(
            $sf1,
            self::REASON,
            $this->correctedSetsForGame($sf1, $newWinnerPlayer),
        )->assertOk();

        $this->assertSame($newWinnerPlayer->id, $final->fresh()->singlesPlayer1Id());
        $this->assertFalse(
            Game::query()
                ->where('bracket_id', $bracket->id)
                ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
                ->exists(),
        );

        $context->finishGame($final->fresh(), $newWinnerPlayer)->assertOk();

        $sf2 = $this->semifinalByMatch($semifinals, 2)->fresh();
        $sf2Loser = (int) $sf2->singlesWinnerId() === (int) $sf2->singlesPlayer1Id() ? (int) $sf2->singlesPlayer2Id() : (int) $sf2->singlesPlayer1Id();

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary.third_place.0.id', $oldSf1WinnerId)
            ->assertJsonPath('data.result_summary.third_place.1.id', $sf2Loser);
    }

    public function test_none_mode_propagates_winner_only(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::None]);
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1)->sortBy('bracket_match')->values();

        $context->finishGame($this->semifinalByMatch($semifinals, 1), $players[0])->assertOk();
        $context->finishGame($this->semifinalByMatch($semifinals, 2), $players[2])->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->sole();
        $sf1 = $this->semifinalByMatch($semifinals, 1)->fresh([...Game::DISPLAY_RELATIONS, 'competition']);
        $newWinnerPlayer = (int) $sf1->singlesPlayer1Id() === (int) $sf1->singlesWinnerId() ? $sf1->singlesPlayer2() : $sf1->singlesPlayer1();

        $context->correctResult(
            $sf1,
            self::REASON,
            $this->correctedSetsForGame($sf1, $newWinnerPlayer),
        )->assertOk();

        $this->assertSame($newWinnerPlayer->id, $final->fresh()->singlesPlayer1Id());

        $activity = Activity::query()
            ->where('description', AuditAction::GAME_RESULT_CORRECTED->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('not_applicable', data_get($activity->properties, 'summary.propagation.loser.reason'));
    }

    public function test_unchanged_winner_does_not_touch_downstream(): void
    {
        $setup = $this->setupPlayoffAfterSemifinals();
        $context = $setup['context'];
        $sf1 = $setup['semifinals'][0]->fresh([...Game::DISPLAY_RELATIONS, 'competition', 'sets']);
        $beforeFinal = $setup['final']->only(['entry1_id', 'entry2_id']);
        $beforeThird = $setup['thirdPlace']->only(['entry1_id', 'entry2_id']);

        $context->correctResult(
            $sf1,
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
            ],
        )->assertOk();

        $this->assertSame($beforeFinal, $setup['final']->fresh()->only(['entry1_id', 'entry2_id']));
        $this->assertSame($beforeThird, $setup['thirdPlace']->fresh()->only(['entry1_id', 'entry2_id']));
    }

    /**
     * @return array{
     *     context: \Tests\Support\TournamentTestContext,
     *     competition: \App\Models\Competition,
     *     bracket: Bracket,
     *     players: array<int, Player>,
     *     semifinals: \Illuminate\Support\Collection<int, Game>,
     *     final: Game,
     *     thirdPlace: Game,
     * }
     */
    private function setupPlayoffAfterSemifinals(): array
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Playoff]);
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1)->sortBy('bracket_match')->values();

        $context->finishGame($this->semifinalByMatch($semifinals, 1), $players[0])->assertOk();
        $context->finishGame($this->semifinalByMatch($semifinals, 2), $players[2])->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->sole();
        $thirdPlace = Game::query()
            ->where('bracket_id', $bracket->id)
            ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
            ->sole();

        return compact('context', 'competition', 'bracket', 'players', 'semifinals', 'final', 'thirdPlace');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Game>|array<int, Game>  $semifinals
     */
    private function semifinalByMatch($semifinals, int $match): Game
    {
        $collection = is_array($semifinals) ? collect($semifinals) : $semifinals;

        return $collection->firstOrFail(fn (Game $game): bool => (int) $game->bracket_match === $match);
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
            $winnerId = (int) $winner->id;
            $sets[] = [
                'player1_score' => (int) $game->singlesPlayer1Id() === $winnerId ? $pointsPerSet : ($pointsPerSet - 2),
                'player2_score' => (int) $game->singlesPlayer2Id() === $winnerId ? $pointsPerSet : ($pointsPerSet - 2),
            ];
        }

        return $sets;
    }
}
