<?php

namespace Tests\Unit\Audit;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Competition;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    public function test_persists_stable_action_code_log_name_subject_and_properties(): void
    {
        $this->bootstrapKeycloak();

        $tournament = Tournament::query()->create([
            'name' => 'Torneo Unit',
            'location' => 'Club',
            'start_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $competition = Competition::query()->create([
            'tournament_id' => $tournament->id,
            'name' => 'Comp Unit',
            'type' => 'singles',
            'category' => 'primera',
            'format' => 'groups_knockout',
            'sets_to_win' => 2,
            'points_per_set' => 11,
        ]);

        $user = User::query()->create([
            'keycloak_id' => 'audit-unit-subject',
            'name' => 'Auditor',
            'email' => 'audit-unit@example.com',
            'password' => bcrypt('secret'),
        ]);

        Auth::setUser($user);

        $activity = app(AuditLogger::class)->log(new AuditEntry(
            action: AuditAction::GROUPS_REGENERATED,
            logName: 'groups',
            subject: $competition,
            context: [
                'tournament_id' => $tournament->id,
                'competition_id' => $competition->id,
            ],
            old: ['groups_count' => 2],
            new: ['groups_count' => 3],
            summary: ['groups_created' => 3],
            reason: 'test reason',
        ));

        $this->assertSame(AuditAction::GROUPS_REGENERATED->value, $activity->description);
        $this->assertSame('groups', $activity->log_name);
        $this->assertSame(Competition::class, $activity->subject_type);
        $this->assertSame($competition->id, $activity->subject_id);
        $this->assertSame($user->id, $activity->causer_id);

        $properties = $activity->properties->toArray();

        $this->assertSame(1, $properties['schema_version']);
        $this->assertSame($tournament->id, $properties['context']['tournament_id']);
        $this->assertSame(2, $properties['old']['groups_count']);
        $this->assertSame(3, $properties['new']['groups_count']);
        $this->assertSame(3, $properties['summary']['groups_created']);
        $this->assertSame('test reason', $properties['reason']);
        $this->assertSame('audit-unit-subject', $properties['actor']['keycloak_id']);
        $this->assertNull($properties['request']['ip_address']);
        $this->assertNull($properties['request']['user_agent']);
        $this->assertArrayNotHasKey('Authorization', $properties);
        $this->assertArrayNotHasKey('token', $properties);
    }

    public function test_works_without_authenticated_user(): void
    {
        Auth::logout();

        $tournament = Tournament::query()->create([
            'name' => 'Torneo Anónimo',
            'location' => 'Club',
            'start_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $competition = Competition::query()->create([
            'tournament_id' => $tournament->id,
            'name' => 'Comp Anónima',
            'type' => 'singles',
            'category' => 'primera',
            'format' => 'groups_knockout',
            'sets_to_win' => 2,
            'points_per_set' => 11,
        ]);

        $activity = app(AuditLogger::class)->log(new AuditEntry(
            action: AuditAction::BRACKET_CREATED,
            logName: 'bracket',
            subject: $competition,
        ));

        $this->assertNull($activity->causer_id);
        $this->assertNull(data_get($activity->properties, 'actor.keycloak_id'));
    }

    public function test_works_without_http_request_metadata(): void
    {
        $tournament = Tournament::query()->create([
            'name' => 'Torneo CLI',
            'location' => 'Club',
            'start_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $competition = Competition::query()->create([
            'tournament_id' => $tournament->id,
            'name' => 'Comp CLI',
            'type' => 'singles',
            'category' => 'primera',
            'format' => 'groups_knockout',
            'sets_to_win' => 2,
            'points_per_set' => 11,
        ]);

        $activity = app(AuditLogger::class)->log(new AuditEntry(
            action: AuditAction::GAME_SET_RECORDED,
            logName: 'games',
            subject: $competition,
        ));

        $this->assertNull(data_get($activity->properties, 'request.ip_address'));
        $this->assertNull(data_get($activity->properties, 'request.user_agent'));
    }

    public function test_activity_log_rolls_back_when_transaction_fails(): void
    {
        $tournamentCountBefore = Tournament::query()->count();
        $activityCountBefore = Activity::query()->count();

        try {
            DB::transaction(function (): void {
                $tournament = Tournament::query()->create([
                    'name' => 'Rollback Test',
                    'location' => 'Club',
                    'start_date' => now()->toDateString(),
                    'status' => 'draft',
                ]);

                app(AuditLogger::class)->log(new AuditEntry(
                    action: AuditAction::GROUPS_REGENERATED,
                    logName: 'groups',
                    subject: $tournament,
                ));

                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($tournamentCountBefore, Tournament::query()->count());
        $this->assertSame($activityCountBefore, Activity::query()->count());
    }
}
