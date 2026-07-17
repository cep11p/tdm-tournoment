<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class GameSetAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
        $this->resetKeycloakClock();
        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_record_set_creates_exactly_one_activity(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 7)
            ->assertOk();

        $this->assertDatabaseCount('activity_log', 1);

        $activity = Activity::query()->sole();

        $this->assertSame(AuditAction::GAME_SET_RECORDED->value, $activity->description);
        $this->assertSame('games', $activity->log_name);
    }

    public function test_record_set_causer_matches_keycloak_user(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 7)
            ->assertOk();

        $user = User::query()->where('keycloak_id', 'test-subject-1')->firstOrFail();
        $activity = Activity::query()->sole();

        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame('test-subject-1', data_get($activity->properties, 'actor.keycloak_id'));
        $this->assertNotNull(data_get($activity->properties, 'request.ip_address'));
        $this->assertNotNull(data_get($activity->properties, 'request.user_agent'));
    }

    public function test_record_set_stores_score_and_set_number(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 7)
            ->assertOk();

        $activity = Activity::query()->sole();

        $this->assertSame(1, data_get($activity->properties, 'summary.set_number'));
        $this->assertSame(11, data_get($activity->properties, 'summary.player1_score'));
        $this->assertSame(7, data_get($activity->properties, 'summary.player2_score'));
        $this->assertSame(0, data_get($activity->properties, 'old.player1_sets_won'));
        $this->assertSame(1, data_get($activity->properties, 'new.player1_sets_won'));
        $this->assertSame(GameStatus::InProgress->value, data_get($activity->properties, 'new.status'));
    }

    public function test_record_set_match_finished_flag_is_correct(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 2, pointsPerSet: 11);

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 5)->assertOk();
        $context->recordSet($setup['game'], setNumber: 2, player1Score: 11, player2Score: 6)->assertOk();

        $activity = Activity::query()->orderByDesc('id')->firstOrFail();

        $this->assertTrue(data_get($activity->properties, 'summary.match_finished'));
        $this->assertSame($setup['playerOne']->id, data_get($activity->properties, 'summary.winner_id'));
        $this->assertSame(GameStatus::Finished->value, data_get($activity->properties, 'new.status'));
    }

    public function test_record_set_on_finished_game_does_not_add_activity(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 2, pointsPerSet: 11);

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 4)->assertOk();
        $context->recordSet($setup['game'], setNumber: 2, player1Score: 11, player2Score: 6)->assertOk();

        $countAfterFinish = Activity::query()->count();

        $context->recordSet($setup['game'], setNumber: 3, player1Score: 11, player2Score: 2)
            ->assertUnprocessable();

        $this->assertSame($countAfterFinish, Activity::query()->count());
    }
}
