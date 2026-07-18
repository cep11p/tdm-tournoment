<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Models\Game;
use App\Models\Player;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class GameResultCorrectionAuditTest extends TestCase
{
    private const REASON = 'El árbitro informó que el marcador del segundo set fue cargado incorrectamente.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
        $this->resetKeycloakClock();
        $this->withHeaders($this->authHeaders(['admin']));
    }

    protected function tearDown(): void
    {
        $this->resetKeycloakClock();

        parent::tearDown();
    }

    public function test_successful_correction_creates_exactly_one_activity(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGame($context);
        $auditCountBefore = Activity::query()->count();

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 8, 'player2_score' => 11],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        )->assertOk();

        $this->assertSame(1, Activity::query()->count() - $auditCountBefore);

        $activity = Activity::query()
            ->where('description', AuditAction::GAME_RESULT_CORRECTED->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(AuditAction::GAME_RESULT_CORRECTED->value, $activity->description);
        $this->assertSame('games', $activity->log_name);
        $this->assertSame($setup['game']->id, $activity->subject_id);
        $this->assertSame(Game::class, $activity->subject_type);
    }

    public function test_correction_causer_matches_keycloak_user(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGame($context);

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        )->assertOk();

        $user = User::query()->where('keycloak_id', 'test-subject-1')->firstOrFail();
        $activity = Activity::query()
            ->where('description', AuditAction::GAME_RESULT_CORRECTED->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame('test-subject-1', data_get($activity->properties, 'actor.keycloak_id'));
        $this->assertNotNull(data_get($activity->properties, 'request.ip_address'));
        $this->assertNotNull(data_get($activity->properties, 'request.user_agent'));
    }

    public function test_correction_stores_reason_and_snapshots(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGame($context);

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 8, 'player2_score' => 11],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        )->assertOk();

        $activity = Activity::query()
            ->where('description', AuditAction::GAME_RESULT_CORRECTED->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(self::REASON, data_get($activity->properties, 'reason'));
        $this->assertSame(GameStatus::Finished->value, data_get($activity->properties, 'old.status'));
        $this->assertSame(GameStatus::Finished->value, data_get($activity->properties, 'new.status'));
        $this->assertSame($setup['playerOne']->id, data_get($activity->properties, 'old.winner_id'));
        $this->assertSame($setup['playerOne']->id, data_get($activity->properties, 'new.winner_id'));
        $this->assertCount(2, data_get($activity->properties, 'old.sets'));
        $this->assertCount(3, data_get($activity->properties, 'new.sets'));
        $this->assertFalse(data_get($activity->properties, 'summary.winner_changed'));
        $this->assertSame(2, data_get($activity->properties, 'summary.sets_count_before'));
        $this->assertSame(3, data_get($activity->properties, 'summary.sets_count_after'));
        $this->assertFalse(data_get($activity->properties, 'summary.propagation.applied'));
    }

    public function test_correction_without_next_round_records_propagation_not_applied(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGame($context);

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        )->assertOk();

        $activity = Activity::query()
            ->where('description', AuditAction::GAME_RESULT_CORRECTED->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertFalse(data_get($activity->properties, 'summary.propagation.applied'));
        $this->assertNull(data_get($activity->properties, 'summary.propagation.reason'));
    }

    public function test_correction_with_propagation_records_destination_details(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createQuarterfinalWithSemifinalPropagation($context);

        $quarterfinalOne = $setup['quarterfinal'];
        $semifinalOne = $setup['semifinal'];
        $oldWinnerId = $quarterfinalOne->fresh()->winner_id;
        $newWinner = $quarterfinalOne->player2;

        $context->correctResult(
            $quarterfinalOne->fresh(),
            self::REASON,
            $this->correctedSetsForGame($quarterfinalOne->fresh(), $newWinner),
        )->assertOk();

        $activity = Activity::query()
            ->where('description', AuditAction::GAME_RESULT_CORRECTED->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue(data_get($activity->properties, 'summary.winner_changed'));
        $this->assertTrue(data_get($activity->properties, 'summary.propagation.applied'));
        $this->assertSame($semifinalOne->id, data_get($activity->properties, 'summary.propagation.destination_game_id'));
        $this->assertSame('player1_id', data_get($activity->properties, 'summary.propagation.slot'));
        $this->assertSame($oldWinnerId, data_get($activity->properties, 'summary.propagation.old_player_id'));
        $this->assertSame($newWinner->id, data_get($activity->properties, 'summary.propagation.new_player_id'));
        $this->assertSame($oldWinnerId, data_get($activity->properties, 'summary.propagation.before.player1_id'));
        $this->assertSame($newWinner->id, data_get($activity->properties, 'summary.propagation.after.player1_id'));
    }

    public function test_correction_with_unchanged_winner_records_propagation_reason(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createQuarterfinalWithSemifinalPropagation($context);

        $context->correctResult(
            $setup['quarterfinal']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 8],
            ],
        )->assertOk();

        $activity = Activity::query()
            ->where('description', AuditAction::GAME_RESULT_CORRECTED->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertFalse(data_get($activity->properties, 'summary.propagation.applied'));
        $this->assertSame('winner_unchanged', data_get($activity->properties, 'summary.propagation.reason'));
    }

    public function test_failed_propagation_does_not_create_activity(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createQuarterfinalWithSemifinalPropagation($context);

        $context->recordSet($setup['semifinal'], setNumber: 1, player1Score: 11, player2Score: 9)->assertOk();
        $setup['semifinal']->update(['status' => GameStatus::Pending, 'winner_id' => null, 'finished_at' => null]);

        $correctionCountBefore = Activity::query()
            ->where('description', AuditAction::GAME_RESULT_CORRECTED->value)
            ->count();

        $context->correctResult(
            $setup['quarterfinal']->fresh(),
            self::REASON,
            $this->correctedSetsForGame($setup['quarterfinal']->fresh(), $setup['quarterfinal']->player2),
        )->assertUnprocessable();

        $this->assertSame(
            $correctionCountBefore,
            Activity::query()->where('description', AuditAction::GAME_RESULT_CORRECTED->value)->count(),
        );
    }

    public function test_correction_summary_reflects_winner_change(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGame($context);

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 8, 'player2_score' => 11],
                ['player1_score' => 9, 'player2_score' => 11],
            ],
        )->assertOk();

        $activity = Activity::query()
            ->where('description', AuditAction::GAME_RESULT_CORRECTED->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue(data_get($activity->properties, 'summary.winner_changed'));
        $this->assertSame($setup['playerOne']->id, data_get($activity->properties, 'summary.old_winner_id'));
        $this->assertSame($setup['playerTwo']->id, data_get($activity->properties, 'summary.new_winner_id'));
    }

    public function test_failed_correction_does_not_create_activity(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGame($context);
        $correctionCountBefore = Activity::query()
            ->where('description', AuditAction::GAME_RESULT_CORRECTED->value)
            ->count();

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
            $correctionCountBefore,
            Activity::query()->where('description', AuditAction::GAME_RESULT_CORRECTED->value)->count(),
        );
    }

    public function test_correction_does_not_create_game_set_recorded_activities(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGame($context);
        $setRecordedCountBefore = Activity::query()
            ->where('description', AuditAction::GAME_SET_RECORDED->value)
            ->count();

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        )->assertOk();

        $this->assertSame(
            $setRecordedCountBefore,
            Activity::query()->where('description', AuditAction::GAME_SET_RECORDED->value)->count(),
        );
    }

    /**
     * @return array{
     *     competition: \App\Models\Competition,
     *     playerOne: \App\Models\Player,
     *     playerTwo: \App\Models\Player,
     *     game: \App\Models\Game,
     * }
     */
    private function createFinishedGame(\Tests\Support\TournamentTestContext $context): array
    {
        $setup = $context->createPendingSinglesGame(setsToWin: 2, pointsPerSet: 11);

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 5)->assertOk();
        $context->recordSet($setup['game'], setNumber: 2, player1Score: 11, player2Score: 6)->assertOk();

        return $setup;
    }

    /**
     * @return array{
     *     quarterfinal: Game,
     *     semifinal: Game,
     * }
     */
    private function createQuarterfinalWithSemifinalPropagation(\Tests\Support\TournamentTestContext $context): array
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
                Player::query()->findOrFail($quarterfinal->player1_id),
            )->assertOk();
        }

        $context->generateBracketNextRound($bracket)->assertCreated();

        return [
            'quarterfinal' => $quarterfinals[0]->fresh(),
            'semifinal' => $context->bracketGamesForRound($bracket->fresh(), 2)->sortBy('bracket_match')->first(),
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
}
