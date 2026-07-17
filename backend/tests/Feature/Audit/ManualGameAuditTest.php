<?php

namespace Tests\Feature\Audit;

use App\Actions\Game\DeleteManualGameAction;
use App\Enums\AuditAction;
use App\Http\Resources\Audit\AuditLogResource;
use App\Models\Bracket;
use App\Models\Game;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ManualGameAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
        $this->resetKeycloakClock();
        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_manual_create_via_http_generates_game_created_activity(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $activity = Activity::query()
            ->where('description', AuditAction::GAME_CREATED->value)
            ->sole();

        $this->assertSame('games', $activity->log_name);
        $this->assertSame(Game::class, $activity->subject_type);
        $this->assertSame($setup['game']->id, $activity->subject_id);
        $this->assertSame($setup['playerOne']->id, data_get($activity->properties, 'new.player1_id'));
        $this->assertSame($setup['playerTwo']->id, data_get($activity->properties, 'new.player2_id'));
        $this->assertSame('pending', data_get($activity->properties, 'new.status'));
        $this->assertSame($setup['game']->id, data_get($activity->properties, 'summary.game_id'));
    }

    public function test_round_robin_does_not_generate_game_created(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();

        $this->assertSame(0, Activity::query()->where('description', AuditAction::GAME_CREATED->value)->count());
    }

    public function test_bracket_creation_does_not_generate_game_created(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        Activity::query()->delete();

        $context->createBracket($setup['competition'])->assertCreated();

        $this->assertSame(0, Activity::query()->where('description', AuditAction::GAME_CREATED->value)->count());
    }

    public function test_bracket_round_advance_does_not_generate_game_created(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        Activity::query()->delete();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();
        $context->finishGame($semifinals[1], $setup['playerThree'])->assertOk();

        Activity::query()->delete();

        $context->generateBracketNextRound($bracket)->assertCreated();

        $this->assertSame(0, Activity::query()->where('description', AuditAction::GAME_CREATED->value)->count());
    }

    public function test_initial_group_generation_does_not_generate_game_created(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $this->assertSame(0, Activity::query()->where('description', AuditAction::GAME_CREATED->value)->count());
    }

    public function test_manual_delete_generates_game_deleted_with_sets_snapshot(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        Activity::query()->delete();

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 8)->assertOk();

        $gameId = $setup['game']->id;

        $context->deleteGame($setup['game']->fresh())->assertNoContent();

        $this->assertNull(Game::query()->find($gameId));

        $activity = Activity::query()
            ->where('description', AuditAction::GAME_DELETED->value)
            ->sole();

        $this->assertSame('games', $activity->log_name);
        $this->assertSame($gameId, $activity->subject_id);
        $this->assertSame('in_progress', data_get($activity->properties, 'old.status'));
        $this->assertSame(1, data_get($activity->properties, 'summary.sets_removed'));
        $this->assertSame(11, data_get($activity->properties, 'old.sets.0.player1_score'));
        $this->assertSame(8, data_get($activity->properties, 'old.sets.0.player2_score'));
        $this->assertNull(data_get($activity->properties, 'reason'));

        $activity->load(['subject']);
        $payload = (new AuditLogResource($activity))->resolve();

        $this->assertFalse($payload['subject']['exists']);
        $this->assertStringContainsString('vs', $payload['subject']['label']);
    }

    public function test_delete_transaction_rollback_preserves_game_and_activity(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();
        $game = $setup['game'];

        Activity::query()->delete();

        $gamesBefore = Game::query()->count();
        $activityBefore = Activity::query()->count();

        try {
            DB::transaction(function () use ($game): void {
                app(DeleteManualGameAction::class)($game);

                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($gamesBefore, Game::query()->count());
        $this->assertSame($activityBefore, Activity::query()->count());
        $this->assertNotNull(Game::query()->find($game->id));
    }
}
