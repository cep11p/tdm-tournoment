<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Tournament;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CompetitionAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_create_generates_one_activity(): void
    {
        $tournament = Tournament::query()->create([
            'name' => 'Torneo Comp',
            'location' => 'Club',
            'start_date' => '2026-07-01',
            'status' => 'draft',
        ]);

        $primera = Category::query()->where('slug', 'primera')->firstOrFail();

        $response = $this->postJson("/api/v1/tournaments/{$tournament->id}/competitions", [
            'name' => 'Primera Caballeros',
            'category_id' => $primera->id,
            'type' => 'singles',
            'format' => 'groups_knockout',
            'points_per_set' => 11,
        ]);

        $response->assertCreated();

        $activity = Activity::query()
            ->where('description', AuditAction::COMPETITION_CREATED->value)
            ->sole();

        $this->assertSame('competitions', $activity->log_name);
        $this->assertSame(Competition::class, $activity->subject_type);
        $this->assertSame('Primera Caballeros', data_get($activity->properties, 'new.name'));
        $this->assertSame('groups_knockout', data_get($activity->properties, 'new.format'));
        $this->assertSame($primera->id, data_get($activity->properties, 'new.category_id'));
        $this->assertSame($primera->name, data_get($activity->properties, 'new.category_name'));
        $this->assertArrayNotHasKey('sets_to_win', data_get($activity->properties, 'new', []));
    }

    public function test_update_with_changes_generates_old_and_new(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        Activity::query()->delete();

        $this->patchJson("/api/v1/competitions/{$competition->id}", [
            'name' => 'Nombre Actualizado',
            'points_per_set' => 21,
        ])->assertOk();

        $activity = Activity::query()->sole();

        $this->assertSame(AuditAction::COMPETITION_UPDATED->value, $activity->description);
        $this->assertSame($competition->name, data_get($activity->properties, 'old.name'));
        $this->assertSame('Nombre Actualizado', data_get($activity->properties, 'new.name'));
        $this->assertSame(11, data_get($activity->properties, 'old.points_per_set'));
        $this->assertSame(21, data_get($activity->properties, 'new.points_per_set'));
    }

    public function test_update_without_changes_does_not_generate_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        Activity::query()->delete();

        $this->patchJson("/api/v1/competitions/{$competition->id}", [
            'name' => $competition->name,
            'points_per_set' => $competition->points_per_set,
        ])->assertOk();

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_category_change_includes_category_names(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $segunda = Category::query()->where('slug', 'segunda')->firstOrFail();

        Activity::query()->delete();

        $this->patchJson("/api/v1/competitions/{$competition->id}", [
            'category_id' => $segunda->id,
        ])->assertOk();

        $activity = Activity::query()->sole();

        $this->assertSame($competition->category_id, data_get($activity->properties, 'old.category_id'));
        $this->assertSame($segunda->id, data_get($activity->properties, 'new.category_id'));
        $this->assertSame($segunda->name, data_get($activity->properties, 'new.category_name'));
    }

    public function test_validation_error_does_not_generate_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        Activity::query()->delete();

        $this->patchJson("/api/v1/competitions/{$competition->id}", [
            'points_per_set' => 0,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('activity_log', 0);
    }
}
