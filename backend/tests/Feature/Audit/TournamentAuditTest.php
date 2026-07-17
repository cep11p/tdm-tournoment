<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Models\Tournament;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TournamentAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_create_generates_one_activity_with_metadata(): void
    {
        $response = $this->postJson('/api/v1/tournaments', [
            'name' => 'Apertura 2026',
            'location' => 'Club Central',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-10',
        ]);

        $response->assertCreated();

        $this->assertDatabaseCount('activity_log', 1);

        $activity = Activity::query()->sole();

        $this->assertSame(AuditAction::TOURNAMENT_CREATED->value, $activity->description);
        $this->assertSame('tournaments', $activity->log_name);
        $this->assertSame(Tournament::class, $activity->subject_type);
        $this->assertSame('Apertura 2026', data_get($activity->properties, 'new.name'));
        $this->assertSame('Club Central', data_get($activity->properties, 'new.location'));
        $this->assertSame('2026-08-01', data_get($activity->properties, 'new.start_date'));
        $this->assertSame('draft', data_get($activity->properties, 'new.status'));
        $this->assertSame('Apertura 2026', data_get($activity->properties, 'summary.tournament_name'));
    }

    public function test_update_with_changes_generates_old_and_new(): void
    {
        $tournament = Tournament::query()->create([
            'name' => 'Original',
            'location' => 'Sede A',
            'start_date' => '2026-07-01',
            'status' => 'draft',
        ]);

        Activity::query()->delete();

        $this->patchJson("/api/v1/tournaments/{$tournament->id}", [
            'location' => 'Sede B',
            'start_date' => '2026-07-15',
        ])->assertOk();

        $activity = Activity::query()->sole();

        $this->assertSame(AuditAction::TOURNAMENT_UPDATED->value, $activity->description);
        $this->assertSame('Sede A', data_get($activity->properties, 'old.location'));
        $this->assertSame('Sede B', data_get($activity->properties, 'new.location'));
        $this->assertSame('2026-07-01', data_get($activity->properties, 'old.start_date'));
        $this->assertSame('2026-07-15', data_get($activity->properties, 'new.start_date'));
        $this->assertSame(['location', 'start_date'], data_get($activity->properties, 'summary.changed_fields'));
    }

    public function test_update_without_changes_does_not_generate_activity(): void
    {
        $tournament = Tournament::query()->create([
            'name' => 'Sin Cambios',
            'location' => 'Club',
            'start_date' => '2026-07-01',
            'status' => 'draft',
        ]);

        Activity::query()->delete();

        $this->patchJson("/api/v1/tournaments/{$tournament->id}", [
            'name' => 'Sin Cambios',
            'location' => 'Club',
            'start_date' => '2026-07-01',
        ])->assertOk();

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_validation_error_does_not_generate_activity(): void
    {
        $this->postJson('/api/v1/tournaments', [
            'name' => '',
            'location' => '',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_transaction_rollback_does_not_persist_tournament_or_activity(): void
    {
        $tournamentCountBefore = Tournament::query()->count();
        $activityCountBefore = Activity::query()->count();

        try {
            DB::transaction(function (): void {
                app(\App\Actions\Tournament\CreateTournamentAction::class)([
                    'name' => 'Rollback Torneo',
                    'location' => 'Club',
                    'start_date' => '2026-07-01',
                    'status' => 'draft',
                ]);

                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($tournamentCountBefore, Tournament::query()->count());
        $this->assertSame($activityCountBefore, Activity::query()->count());
    }
}
