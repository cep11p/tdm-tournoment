<?php

namespace Tests\Feature\Audit;

use App\Actions\Group\GenerateRandomGroupsForCompetitionAction;
use App\Enums\AuditAction;
use App\Models\Competition;
use App\Models\Group;
use App\Models\GroupPlayer;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class GroupGenerationAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
        $this->resetKeycloakClock();
        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_initial_generation_creates_exactly_one_groups_generated_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $this->assertDatabaseCount('activity_log', 1);

        $activity = Activity::query()->sole();

        $this->assertSame(AuditAction::GROUPS_GENERATED->value, $activity->description);
        $this->assertSame('groups', $activity->log_name);
        $this->assertSame(Competition::class, $activity->subject_type);
        $this->assertSame($competition->id, $activity->subject_id);
        $this->assertSame(2, data_get($activity->properties, 'summary.requested_groups_count'));
        $this->assertSame(2, data_get($activity->properties, 'summary.groups_created'));
        $this->assertSame(6, data_get($activity->properties, 'summary.players_assigned'));
        $this->assertSame(2, data_get($activity->properties, 'new.groups_count'));
        $this->assertGreaterThan(0, data_get($activity->properties, 'summary.games_created'));
        $this->assertSame(
            data_get($activity->properties, 'summary.games_created'),
            data_get($activity->properties, 'new.games_count'),
        );
    }

    public function test_initial_generation_does_not_create_child_activities(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $this->assertSame(0, Activity::query()->where('description', AuditAction::GROUP_CREATED->value)->count());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GROUP_PLAYER_ASSIGNED->value)->count());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GROUPS_ROUND_ROBIN_GENERATED->value)->count());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GAME_CREATED->value)->count());
    }

    public function test_validation_error_does_not_create_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $context->createGroup($competition, 'Grupo A');

        $context->generateRandomGroups($competition, groupsCount: 2)
            ->assertUnprocessable();

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_transaction_rollback_reverts_groups_and_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $groupsBefore = Group::query()->count();
        $groupPlayersBefore = GroupPlayer::query()->count();
        $activityBefore = Activity::query()->count();

        try {
            DB::transaction(function () use ($competition): void {
                app(GenerateRandomGroupsForCompetitionAction::class)($competition, 2);

                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($groupsBefore, Group::query()->count());
        $this->assertSame($groupPlayersBefore, GroupPlayer::query()->count());
        $this->assertSame($activityBefore, Activity::query()->count());
    }
}
