<?php

namespace Tests\Unit\Audit;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Http\Resources\Audit\AuditLogDetailResource;
use App\Http\Resources\Audit\AuditLogResource;
use App\Models\Competition;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuditLogResourceTest extends TestCase
{
    public function test_list_resource_does_not_expose_sensitive_fields(): void
    {
        $activity = $this->createActivityWithFullProperties();

        $payload = (new AuditLogResource($activity))->resolve();

        $this->assertArrayNotHasKey('old', $payload);
        $this->assertArrayNotHasKey('new', $payload);
        $this->assertArrayNotHasKey('reason', $payload);
        $this->assertArrayNotHasKey('request', $payload);
        $this->assertArrayNotHasKey('schema_version', $payload);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertSame('Regeneración de grupos', $payload['action_label']);
        $this->assertSame('Grupos', $payload['category_label']);
    }

    public function test_detail_resource_exposes_expected_fields(): void
    {
        $activity = Activity::query()->create([
            'log_name' => 'groups',
            'description' => AuditAction::GROUPS_REGENERATED->value,
            'properties' => [
                'schema_version' => 1,
                'old' => ['groups_removed' => 2],
                'new' => ['groups_created' => 3],
                'summary' => ['groups_created' => 3],
                'reason' => 'Motivo de prueba',
                'request' => [
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'PHPUnit',
                ],
            ],
        ]);

        $payload = (new AuditLogDetailResource($activity))->resolve();

        $this->assertSame(['groups_removed' => 2], $payload['old']);
        $this->assertSame(['groups_created' => 3], $payload['new']);
        $this->assertSame('Motivo de prueba', $payload['reason']);
        $this->assertSame('127.0.0.1', $payload['request']['ip_address']);
        $this->assertSame('PHPUnit', $payload['request']['user_agent']);
        $this->assertSame(1, $payload['schema_version']);
    }

    public function test_list_resource_handles_deleted_actor(): void
    {
        $activity = Activity::query()->create([
            'log_name' => 'groups',
            'description' => AuditAction::GROUPS_REGENERATED->value,
            'subject_type' => Competition::class,
            'subject_id' => 99,
            'causer_type' => User::class,
            'causer_id' => 9999,
            'properties' => [
                'actor' => ['keycloak_id' => 'deleted-subject'],
            ],
        ]);

        $payload = (new AuditLogResource($activity))->resolve();

        $this->assertSame(['keycloak_id' => 'deleted-subject'], $payload['actor']);
    }

    public function test_subject_presenter_uses_historical_context_when_model_missing(): void
    {
        $activity = Activity::query()->create([
            'log_name' => 'groups',
            'description' => AuditAction::GROUPS_REGENERATED->value,
            'subject_type' => Competition::class,
            'subject_id' => 404,
            'properties' => [
                'context' => [
                    'competition_id' => 404,
                    'competition_name' => 'Competencia eliminada',
                ],
            ],
        ]);

        $payload = (new AuditLogResource($activity))->resolve();

        $this->assertSame('competition', $payload['subject']['type']);
        $this->assertSame(404, $payload['subject']['id']);
        $this->assertSame('Competencia eliminada', $payload['subject']['label']);
        $this->assertFalse($payload['subject']['exists']);
    }

    public function test_resources_tolerate_incomplete_properties(): void
    {
        $activity = Activity::query()->create([
            'log_name' => 'groups',
            'description' => AuditAction::GROUPS_REGENERATED->value,
            'properties' => [],
        ]);

        $listPayload = (new AuditLogResource($activity))->resolve();
        $detailPayload = (new AuditLogDetailResource($activity))->resolve();

        $this->assertArrayHasKey('tournament_id', $listPayload['context']);
        $this->assertSame([], $listPayload['summary']);
        $this->assertNull($listPayload['actor']);
        $this->assertNull($listPayload['subject']);
        $this->assertSame([], $detailPayload['old']);
        $this->assertSame([], $detailPayload['new']);
        $this->assertNull($detailPayload['schema_version']);
    }

    public function test_unknown_action_uses_code_as_label(): void
    {
        $activity = Activity::query()->create([
            'log_name' => 'groups',
            'description' => 'future.unknown_action',
            'properties' => [],
        ]);

        $payload = (new AuditLogResource($activity))->resolve();

        $this->assertSame('future.unknown_action', $payload['action_label']);
        $this->assertSame('Grupos', $payload['category_label']);
    }

    public function test_subject_alias_is_public(): void
    {
        $competition = $this->createCompetition();

        $activity = app(AuditLogger::class)->log(new AuditEntry(
            action: AuditAction::GROUPS_REGENERATED,
            logName: 'groups',
            subject: $competition,
        ));

        $activity->load(['subject']);

        $payload = (new AuditLogResource($activity))->resolve();

        $this->assertSame('competition', $payload['subject']['type']);
        $this->assertSame($competition->id, $payload['subject']['id']);
        $this->assertSame($competition->name, $payload['subject']['label']);
        $this->assertTrue($payload['subject']['exists']);
    }

    private function createActivityWithFullProperties(): Activity
    {
        $user = User::factory()->create([
            'keycloak_id' => 'resource-test-subject',
            'name' => 'Admin Test',
            'email' => 'admin-test@example.com',
        ]);

        Auth::setUser($user);

        $competition = $this->createCompetition();

        return app(AuditLogger::class)->log(new AuditEntry(
            action: AuditAction::GROUPS_REGENERATED,
            logName: 'groups',
            subject: $competition,
            context: [
                'tournament_id' => $competition->tournament_id,
                'competition_id' => $competition->id,
                'competition_name' => $competition->name,
            ],
            old: ['groups_removed' => 2],
            new: ['groups_created' => 3],
            summary: ['groups_created' => 3],
            reason: 'Motivo de prueba',
        ))->load(['causer', 'subject']);
    }

    private function createCompetition(): Competition
    {
        $tournament = Tournament::query()->create([
            'name' => 'Torneo Resource',
            'location' => 'Club',
            'start_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        return Competition::query()->create([
            'tournament_id' => $tournament->id,
            'name' => 'Comp Resource',
            'type' => 'singles',
            'category' => 'primera',
            'format' => 'groups_knockout',
            'sets_to_win' => 2,
            'points_per_set' => 11,
        ]);
    }
}
