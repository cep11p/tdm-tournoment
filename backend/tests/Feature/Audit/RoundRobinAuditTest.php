<?php

namespace Tests\Feature\Audit;

use App\Actions\Group\GenerateGroupRoundRobinGamesAction;
use App\Enums\AuditAction;
use App\Models\Game;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RoundRobinAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
        $this->resetKeycloakClock();
        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_round_robin_creates_exactly_one_activity_with_game_count(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();

        $this->assertSame(1, Activity::query()->count());

        $activity = Activity::query()->sole();
        $gamesCreated = Game::query()->where('group_id', $group->id)->count();

        $this->assertSame(AuditAction::GROUPS_ROUND_ROBIN_GENERATED->value, $activity->description);
        $this->assertSame('groups', $activity->log_name);
        $this->assertSame($gamesCreated, data_get($activity->properties, 'summary.games_created'));
        $this->assertSame($gamesCreated, data_get($activity->properties, 'new.games_count'));
        $this->assertSame(4, data_get($activity->properties, 'summary.player_count'));
        $this->assertSame(0, data_get($activity->properties, 'summary.existing_games_before'));
    }

    public function test_round_robin_does_not_create_game_created_activities(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();

        $this->assertSame(0, Activity::query()->where('description', AuditAction::GAME_CREATED->value)->count());
    }

    public function test_second_round_robin_execution_returns_422_without_extra_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();

        $countAfterFirst = Activity::query()->count();

        $context->generateRoundRobin($group)->assertUnprocessable();

        $this->assertSame($countAfterFirst, Activity::query()->count());
    }

    public function test_round_robin_transaction_rollback_reverts_games_and_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $gamesBefore = Game::query()->count();
        $activityBefore = Activity::query()->count();

        try {
            DB::transaction(function () use ($group): void {
                app(GenerateGroupRoundRobinGamesAction::class)($group);

                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($gamesBefore, Game::query()->count());
        $this->assertSame($activityBefore, Activity::query()->count());
    }
}
