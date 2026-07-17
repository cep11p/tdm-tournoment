<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Models\Competition;
use App\Models\Player;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RegistrationAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_individual_registration_generates_one_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competition, $player)->assertCreated();

        $activity = Activity::query()
            ->where('description', AuditAction::REGISTRATION_CREATED->value)
            ->sole();

        $this->assertSame('registrations', $activity->log_name);
        $this->assertSame(Competition::class, $activity->subject_type);
        $this->assertSame($competition->id, $activity->subject_id);
        $this->assertSame($player->id, data_get($activity->properties, 'new.player_id'));
        $this->assertSame($player->id, data_get($activity->properties, 'summary.player_id'));
        $this->assertSame('Jugador1 Test', data_get($activity->properties, 'summary.player_name'));
        $this->assertNotNull(data_get($activity->properties, 'summary.registration_id'));
    }

    public function test_duplicate_individual_registration_does_not_audit(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competition, $player)->assertCreated();
        Activity::query()->delete();

        $context->registerPlayerViaApi($competition, $player)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_bulk_registration_generates_one_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $playerIds = array_map(static fn (Player $player): int => $player->id, $players);

        $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => $playerIds],
        )->assertOk();

        $this->assertDatabaseCount('activity_log', 1);

        $activity = Activity::query()->sole();

        $this->assertSame(AuditAction::REGISTRATION_BULK_CREATED->value, $activity->description);
        $this->assertSame(3, data_get($activity->properties, 'summary.created_count'));
        $this->assertSame(0, data_get($activity->properties, 'summary.skipped_count'));
        $this->assertSame(3, data_get($activity->properties, 'summary.requested_count'));
    }

    public function test_bulk_does_not_generate_individual_registration_activities(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $playerIds = array_map(static fn (Player $player): int => $player->id, $players);

        $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => $playerIds],
        )->assertOk();

        $this->assertSame(
            0,
            Activity::query()
                ->where('description', AuditAction::REGISTRATION_CREATED->value)
                ->count(),
        );
    }

    public function test_bulk_with_skipped_players_reports_counters(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$registered, $newOne, $newTwo] = $context->createPlayers(3);
        $context->registerPlayer($competition, $registered);

        $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => [$registered->id, $newOne->id, $newTwo->id]],
        )->assertOk();

        $activity = Activity::query()->sole();

        $this->assertSame(2, data_get($activity->properties, 'summary.created_count'));
        $this->assertSame(1, data_get($activity->properties, 'summary.skipped_count'));
        $this->assertSame([$registered->id], data_get($activity->properties, 'summary.skipped_player_ids'));
    }

    public function test_idempotent_bulk_with_zero_created_still_audits(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $playerIds = array_map(static fn (Player $player): int => $player->id, $players);

        $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => $playerIds],
        )->assertOk();

        Activity::query()->delete();

        $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => $playerIds],
        )->assertOk();

        $activity = Activity::query()->sole();

        $this->assertSame(AuditAction::REGISTRATION_BULK_CREATED->value, $activity->description);
        $this->assertSame(0, data_get($activity->properties, 'summary.created_count'));
        $this->assertSame(2, data_get($activity->properties, 'summary.skipped_count'));
    }

    public function test_bulk_over_twenty_players_uses_name_samples_instead_of_id_lists(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(21);
        $playerIds = array_map(static fn (Player $player): int => $player->id, $players);

        $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => $playerIds],
        )->assertOk();

        $activity = Activity::query()->sole();
        $summary = data_get($activity->properties, 'summary', []);

        $this->assertArrayNotHasKey('created_player_ids', $summary);
        $this->assertArrayHasKey('sample_created_names', $summary);
        $this->assertLessThanOrEqual(5, count($summary['sample_created_names']));
    }

    public function test_bulk_validation_error_does_not_audit(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => []],
        )->assertUnprocessable();

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_bulk_transaction_rollback_reverts_registrations_and_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $registrationCountBefore = $competition->registrations()->count();
        $activityCountBefore = Activity::query()->count();

        try {
            DB::transaction(function () use ($competition, $player): void {
                app(\App\Actions\Registration\BulkRegisterPlayersToCompetitionAction::class)(
                    $competition->id,
                    [$player->id],
                );

                throw new RuntimeException('forced rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($registrationCountBefore, $competition->fresh()->registrations()->count());
        $this->assertSame($activityCountBefore, Activity::query()->count());
    }
}
