<?php

namespace Tests\Feature\Audit;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Http\Resources\Audit\AuditLogResource;
use App\Models\Category;
use App\Models\Competition;
use App\Models\Player;
use App\Models\Tournament;
use App\Support\Audit\AuditLogger;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PlayerAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_create_generates_one_activity(): void
    {
        $primera = Category::query()->where('slug', 'primera')->firstOrFail();

        $this->postJson('/api/v1/players', [
            'first_name' => 'Juan',
            'last_name' => 'Pérez',
            'nickname' => 'juanp',
            'category_id' => $primera->id,
        ])->assertCreated();

        $activity = Activity::query()
            ->where('description', AuditAction::PLAYER_CREATED->value)
            ->sole();

        $this->assertSame('players', $activity->log_name);
        $this->assertSame(Player::class, $activity->subject_type);
        $this->assertSame('Juan', data_get($activity->properties, 'new.first_name'));
        $this->assertSame($primera->name, data_get($activity->properties, 'new.category_name'));
        $this->assertTrue(data_get($activity->properties, 'new.active'));
    }

    public function test_update_generates_old_and_new(): void
    {
        $player = Player::query()->create([
            'first_name' => 'María',
            'last_name' => 'Gómez',
        ]);

        Activity::query()->delete();

        $this->patchJson("/api/v1/players/{$player->id}", [
            'last_name' => 'García',
        ])->assertOk();

        $activity = Activity::query()->sole();

        $this->assertSame(AuditAction::PLAYER_UPDATED->value, $activity->description);
        $this->assertSame('Gómez', data_get($activity->properties, 'old.last_name'));
        $this->assertSame('García', data_get($activity->properties, 'new.last_name'));
    }

    public function test_deactivation_uses_deactivated_action(): void
    {
        $player = Player::query()->create([
            'first_name' => 'Pedro',
            'last_name' => 'López',
        ]);

        Activity::query()->delete();

        $this->patchJson("/api/v1/players/{$player->id}", [
            'active' => false,
        ])->assertOk();

        $activity = Activity::query()->sole();

        $this->assertSame(AuditAction::PLAYER_DEACTIVATED->value, $activity->description);
        $this->assertTrue(data_get($activity->properties, 'old.active'));
        $this->assertFalse(data_get($activity->properties, 'new.active'));
    }

    public function test_reactivation_uses_updated_action(): void
    {
        $player = Player::query()->create([
            'first_name' => 'Reactivo',
            'last_name' => 'Jugador',
            'active' => false,
        ]);

        Activity::query()->delete();

        $this->patchJson("/api/v1/players/{$player->id}", [
            'active' => true,
        ])->assertOk();

        $activity = Activity::query()->sole();

        $this->assertSame(AuditAction::PLAYER_UPDATED->value, $activity->description);
    }

    public function test_deactivation_with_other_changes_prioritizes_deactivated_code(): void
    {
        $player = Player::query()->create([
            'first_name' => 'Mixto',
            'last_name' => 'Cambio',
        ]);

        Activity::query()->delete();

        $this->patchJson("/api/v1/players/{$player->id}", [
            'last_name' => 'Nuevo',
            'active' => false,
        ])->assertOk();

        $activity = Activity::query()->sole();

        $this->assertSame(AuditAction::PLAYER_DEACTIVATED->value, $activity->description);
        $this->assertSame('Cambio', data_get($activity->properties, 'old.last_name'));
        $this->assertSame('Nuevo', data_get($activity->properties, 'new.last_name'));
    }

    public function test_orphan_delete_generates_activity_and_removes_player(): void
    {
        $player = Player::query()->create([
            'first_name' => 'Huérfano',
            'last_name' => 'Eliminar',
        ]);

        Activity::query()->delete();

        $this->deleteJson("/api/v1/players/{$player->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('players', ['id' => $player->id]);

        $activity = Activity::query()->sole();

        $this->assertSame(AuditAction::PLAYER_DELETED->value, $activity->description);
        $this->assertSame('Huérfano', data_get($activity->properties, 'old.first_name'));
        $this->assertSame($player->id, $activity->subject_id);
    }

    public function test_delete_with_history_returns_422_and_does_not_audit(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $context->registerPlayer($competition, $player);

        Activity::query()->delete();

        $this->deleteJson("/api/v1/players/{$player->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player']);

        $this->assertDatabaseHas('players', ['id' => $player->id]);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_deleted_player_subject_uses_historical_fallback(): void
    {
        $player = Player::query()->create([
            'first_name' => 'Eliminado',
            'last_name' => 'Fallback',
        ]);

        $playerId = $player->id;

        $this->deleteJson("/api/v1/players/{$playerId}")->assertNoContent();

        $activity = Activity::query()->sole()->load(['subject']);

        $payload = (new AuditLogResource($activity))->resolve();

        $this->assertSame('player', $payload['subject']['type']);
        $this->assertSame($playerId, $payload['subject']['id']);
        $this->assertSame('Eliminado Fallback', $payload['subject']['label']);
        $this->assertFalse($payload['subject']['exists']);
    }

    public function test_no_sensitive_fields_in_audit_properties(): void
    {
        $this->postJson('/api/v1/players', [
            'first_name' => 'Seguro',
            'last_name' => 'Datos',
        ])->assertCreated();

        $properties = Activity::query()->latest('id')->first()->properties->toArray();

        $this->assertArrayNotHasKey('email', $properties['new'] ?? []);
        $this->assertArrayNotHasKey('phone', $properties['new'] ?? []);
        $this->assertArrayNotHasKey('document', $properties['new'] ?? []);
    }

    public function test_update_without_changes_does_not_audit(): void
    {
        $player = Player::query()->create([
            'first_name' => 'Igual',
            'last_name' => 'Estado',
        ]);

        Activity::query()->delete();

        $this->patchJson("/api/v1/players/{$player->id}", [
            'first_name' => 'Igual',
        ])->assertOk();

        $this->assertDatabaseCount('activity_log', 0);
    }
}
