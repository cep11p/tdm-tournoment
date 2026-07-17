<?php

namespace Tests\Feature\Audit;

use App\Actions\Group\CreateGroupAction;
use App\Actions\GroupPlayer\AssignPlayerToGroupAction;
use App\Enums\AuditAction;
use App\Models\Group;
use App\Models\GroupPlayer;
use RuntimeException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class GroupManualOperationsAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
        $this->resetKeycloakClock();
        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_create_group_creates_one_activity_with_context_and_new_name(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $response = $context->createGroupViaApi($competition, 'Grupo C');
        $response->assertCreated();

        $group = Group::query()->findOrFail($response->json('data.id'));

        $activity = Activity::query()
            ->where('description', AuditAction::GROUP_CREATED->value)
            ->sole();

        $this->assertSame('groups', $activity->log_name);
        $this->assertSame(Group::class, $activity->subject_type);
        $this->assertSame($group->id, $activity->subject_id);
        $this->assertSame($competition->id, data_get($activity->properties, 'context.competition_id'));
        $this->assertSame('Grupo C', data_get($activity->properties, 'new.name'));
        $this->assertSame('Grupo C', data_get($activity->properties, 'summary.group_name'));
    }

    public function test_duplicate_group_name_does_not_create_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $context->createGroupViaApi($competition, 'Grupo A')->assertCreated();

        Activity::query()->delete();

        $context->createGroupViaApi($competition, 'Grupo A')
            ->assertUnprocessable();

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_assign_player_creates_one_activity_with_status(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroup($competition, 'Grupo A');

        $context->assignPlayerToGroupViaApi($group, $players[0])->assertCreated();

        $activity = Activity::query()
            ->where('description', AuditAction::GROUP_PLAYER_ASSIGNED->value)
            ->sole();

        $this->assertSame('groups', $activity->log_name);
        $this->assertSame(Group::class, $activity->subject_type);
        $this->assertSame($group->id, $activity->subject_id);
        $this->assertSame($players[0]->id, data_get($activity->properties, 'context.player_id'));
        $this->assertSame('active', data_get($activity->properties, 'new.status'));
        $this->assertSame($group->id, data_get($activity->properties, 'summary.group_id'));
        $this->assertSame($players[0]->id, data_get($activity->properties, 'summary.player_id'));
    }

    public function test_duplicate_assignment_does_not_create_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, [$players[0]]);

        Activity::query()->delete();

        $context->assignPlayerToGroupViaApi($group, $players[0])
            ->assertUnprocessable();

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_unregistered_player_does_not_create_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $group = $context->createGroup($competition, 'Grupo A');

        $context->assignPlayerToGroupViaApi($group, $player)
            ->assertUnprocessable();

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_create_group_transaction_rollback_reverts_domain_and_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $groupsBefore = Group::query()->count();
        $activityBefore = Activity::query()->count();

        try {
            DB::transaction(function () use ($competition): void {
                app(CreateGroupAction::class)([
                    'competition_id' => $competition->id,
                    'name' => 'Rollback Grupo',
                ]);

                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($groupsBefore, Group::query()->count());
        $this->assertSame($activityBefore, Activity::query()->count());
    }

    public function test_assign_player_transaction_rollback_reverts_domain_and_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroup($competition, 'Grupo A');

        $groupPlayersBefore = GroupPlayer::query()->count();
        $activityBefore = Activity::query()->count();

        try {
            DB::transaction(function () use ($group, $players): void {
                app(AssignPlayerToGroupAction::class)([
                    'group_id' => $group->id,
                    'player_id' => $players[0]->id,
                ]);

                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($groupPlayersBefore, GroupPlayer::query()->count());
        $this->assertSame($activityBefore, Activity::query()->count());
    }
}
