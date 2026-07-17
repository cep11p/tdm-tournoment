<?php

namespace Tests\Feature\Auth;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Competition;
use App\Models\Tournament;
use App\Support\Audit\AuditLogger;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuditLogAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
    }

    protected function tearDown(): void
    {
        $this->resetKeycloakClock();

        parent::tearDown();
    }

    public function test_admin_can_list_audit_logs(): void
    {
        $this->getJson('/api/v1/audit-logs', $this->keycloakAuthHeaders(['admin']))
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'links',
                'meta',
            ]);
    }

    public function test_admin_can_show_audit_log_detail(): void
    {
        $activity = $this->createSampleActivity();

        $this->getJson("/api/v1/audit-logs/{$activity->id}", $this->keycloakAuthHeaders(['admin']))
            ->assertOk()
            ->assertJsonPath('data.id', $activity->id);
    }

    public function test_organizer_cannot_list_audit_logs(): void
    {
        $this->getJson('/api/v1/audit-logs', $this->keycloakAuthHeaders(['organizer']))
            ->assertForbidden()
            ->assertJson([
                'message' => 'No autorizado.',
                'code' => 'forbidden',
            ]);
    }

    public function test_scorekeeper_cannot_list_audit_logs(): void
    {
        $this->getJson('/api/v1/audit-logs', $this->keycloakAuthHeaders(['scorekeeper']))
            ->assertForbidden();
    }

    public function test_player_cannot_list_audit_logs(): void
    {
        $this->getJson('/api/v1/audit-logs', $this->keycloakAuthHeaders(['player']))
            ->assertForbidden();
    }

    public function test_organizer_cannot_show_audit_log_detail(): void
    {
        $activity = $this->createSampleActivity();

        $this->getJson("/api/v1/audit-logs/{$activity->id}", $this->keycloakAuthHeaders(['organizer']))
            ->assertForbidden();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/audit-logs')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'No autenticado.',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_admin_receives_404_for_missing_activity(): void
    {
        $this->getJson('/api/v1/audit-logs/999999', $this->keycloakAuthHeaders(['admin']))
            ->assertNotFound();
    }

    private function createSampleActivity(): Activity
    {
        $tournament = Tournament::query()->create([
            'name' => 'Torneo Auth',
            'location' => 'Club',
            'start_date' => now()->toDateString(),
            'status' => 'draft',
        ]);

        $competition = Competition::query()->create([
            'tournament_id' => $tournament->id,
            'name' => 'Comp Auth',
            'type' => 'singles',
            'category' => 'primera',
            'format' => 'groups_knockout',
            'sets_to_win' => 2,
            'points_per_set' => 11,
        ]);

        return app(AuditLogger::class)->log(new AuditEntry(
            action: AuditAction::GROUPS_REGENERATED,
            logName: 'groups',
            subject: $competition,
            context: [
                'tournament_id' => $tournament->id,
                'tournament_name' => $tournament->name,
                'competition_id' => $competition->id,
                'competition_name' => $competition->name,
            ],
        ));
    }
}
