<?php

namespace Tests\Feature\Ranking;

use App\Actions\Ranking\CopyRankingRulesAction;
use App\Actions\Ranking\DeleteRankingAction;
use App\Enums\AuditAction;
use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionType;
use App\Models\Category;
use App\Models\Ranking;
use App\Models\RankingRule;
use App\Models\RankingStanding;
use App\Models\RankingTransaction;
use App\Support\Ranking\RankingMutationGuard;
use Database\Seeders\RankingSeeder;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RankingTestSetup;
use Tests\TestCase;

class RankingAdminTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ranking Singles 2027',
            'competition_type' => CompetitionType::Singles->value,
            'category_id' => null,
            'season' => '2027',
            'starts_at' => null,
            'ends_at' => null,
        ], $overrides);
    }

    public function test_guest_cannot_create_ranking(): void
    {
        $this->postJson('/api/v1/rankings', $this->validPayload())
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'No autenticado.',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_organizer_cannot_create_ranking(): void
    {
        $this->postJson('/api/v1/rankings', $this->validPayload(), $this->keycloakAuthHeaders(['organizer']))
            ->assertForbidden()
            ->assertJson([
                'message' => 'No autorizado.',
                'code' => 'forbidden',
            ]);
    }

    public function test_scorekeeper_cannot_create_ranking(): void
    {
        $this->postJson('/api/v1/rankings', $this->validPayload(), $this->keycloakAuthHeaders(['scorekeeper']))
            ->assertForbidden();
    }

    public function test_guest_cannot_update_or_delete_ranking(): void
    {
        $ranking = RankingTestSetup::ranking();

        $this->patchJson("/api/v1/rankings/{$ranking->id}", ['name' => 'Otro'])
            ->assertUnauthorized();

        $this->deleteJson("/api/v1/rankings/{$ranking->id}")
            ->assertUnauthorized();
    }

    public function test_admin_can_create_inactive_singles_ranking(): void
    {
        $data = $this->postJson(
            '/api/v1/rankings',
            $this->validPayload(),
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertCreated()
            ->json('data');

        $this->assertSame('Ranking Singles 2027', $data['name']);
        $this->assertSame('singles', $data['competition_type']);
        $this->assertSame('2027', $data['season']);
        $this->assertNull($data['category']);
        $this->assertFalse($data['active']);
        $this->assertFalse($data['has_history']);
        $this->assertFalse($data['rules_locked']);
        $this->assertSame([], $data['rules']);
    }

    public function test_create_defaults_active_to_false_when_omitted(): void
    {
        $payload = $this->validPayload();
        unset($payload['active']);

        $this->postJson('/api/v1/rankings', $payload, $this->keycloakAuthHeaders(['admin']))
            ->assertCreated()
            ->assertJsonPath('data.active', false);
    }

    public function test_admin_can_create_doubles_ranking(): void
    {
        $this->postJson(
            '/api/v1/rankings',
            $this->validPayload([
                'name' => 'Ranking Dobles 2027',
                'competition_type' => CompetitionType::Doubles->value,
            ]),
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertCreated()
            ->assertJsonPath('data.competition_type', 'doubles');
    }

    public function test_team_ranking_is_rejected(): void
    {
        $this->postJson(
            '/api/v1/rankings',
            $this->validPayload(['competition_type' => CompetitionType::Team->value]),
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition_type']);
    }

    public function test_create_with_valid_category(): void
    {
        $primera = Category::query()->where('slug', 'primera')->firstOrFail();

        $this->postJson(
            '/api/v1/rankings',
            $this->validPayload(['category_id' => $primera->id]),
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertCreated()
            ->assertJsonPath('data.category.id', $primera->id);
    }

    public function test_create_with_invalid_category_is_rejected(): void
    {
        $this->postJson(
            '/api/v1/rankings',
            $this->validPayload(['category_id' => 99999]),
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_date_range_and_single_dates(): void
    {
        $this->postJson(
            '/api/v1/rankings',
            $this->validPayload([
                'starts_at' => '2027-01-01',
                'ends_at' => '2027-12-31',
            ]),
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertCreated()
            ->assertJsonPath('data.starts_at', '2027-01-01')
            ->assertJsonPath('data.ends_at', '2027-12-31');

        $this->postJson(
            '/api/v1/rankings',
            $this->validPayload([
                'name' => 'Solo desde',
                'starts_at' => '2027-01-01',
                'ends_at' => null,
            ]),
            $this->keycloakAuthHeaders(['admin']),
        )->assertCreated();

        $this->postJson(
            '/api/v1/rankings',
            $this->validPayload([
                'name' => 'Fechas invertidas',
                'starts_at' => '2027-12-31',
                'ends_at' => '2027-01-01',
            ]),
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at']);
    }

    public function test_season_accepts_non_numeric_labels(): void
    {
        $this->postJson(
            '/api/v1/rankings',
            $this->validPayload(['season' => '2026/27']),
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertCreated()
            ->assertJsonPath('data.season', '2026/27');
    }

    public function test_cannot_activate_without_rules(): void
    {
        $this->postJson(
            '/api/v1/rankings',
            $this->validPayload(['active' => true]),
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.active.0', RankingMutationGuard::ACTIVE_WITHOUT_RULES_MESSAGE);

        $ranking = RankingTestSetup::ranking(overrides: ['active' => false]);

        $this->patchJson(
            "/api/v1/rankings/{$ranking->id}",
            ['active' => true],
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.active.0', RankingMutationGuard::ACTIVE_WITHOUT_RULES_MESSAGE);
    }

    public function test_copy_rules_from_same_type_ranking(): void
    {
        $this->seed(RankingSeeder::class);
        $source = Ranking::query()->where('name', RankingSeeder::SINGLES_NAME)->firstOrFail();
        $sourceRules = $source->rules()->reorder()->orderBy('id')->get();

        $this->assertCount(10, $sourceRules);

        Activity::query()->delete();

        $data = $this->postJson(
            '/api/v1/rankings',
            $this->validPayload([
                'active' => true,
                'copy_rules_from_ranking_id' => $source->id,
            ]),
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertCreated()
            ->json('data');

        $this->assertTrue($data['active']);
        $this->assertFalse($data['has_history']);
        $this->assertFalse($data['rules_locked']);
        $this->assertCount(10, $data['rules']);

        $target = Ranking::query()->findOrFail($data['id']);
        $targetRules = $target->rules()->reorder()->orderBy('id')->get();

        $this->assertCount(10, $targetRules);
        $this->assertSame(0, $target->transactions()->count());
        $this->assertSame(0, $target->standings()->count());

        foreach ($sourceRules as $index => $sourceRule) {
            $copied = $targetRules[$index];
            $this->assertNotSame($sourceRule->id, $copied->id);
            $this->assertSame($sourceRule->name, $copied->name);
            $this->assertSame($sourceRule->source, $copied->source);
            $this->assertSame($sourceRule->position, $copied->position);
            $this->assertSame($sourceRule->points, $copied->points);
            $this->assertSame($sourceRule->active, $copied->active);
            $this->assertSame($sourceRule->priority, $copied->priority);
            $this->assertSame(
                $sourceRule->position === null ? 0 : (int) $sourceRule->position,
                (int) $copied->getAttribute('position_key'),
            );
        }

        $this->assertSame(
            1,
            Activity::query()->where('description', AuditAction::RANKING_CREATED->value)->count(),
        );
        $this->assertSame(
            0,
            Activity::query()->where('description', AuditAction::RANKING_RULE_CREATED->value)->count(),
        );

        $activity = Activity::query()
            ->where('description', AuditAction::RANKING_CREATED->value)
            ->sole();

        $this->assertSame('rankings', $activity->log_name);
        $this->assertSame(Ranking::class, $activity->subject_type);
        $this->assertSame($source->id, data_get($activity->properties, 'summary.copied_from_ranking_id'));
        $this->assertSame(10, data_get($activity->properties, 'summary.rules_copied_count'));

        $this->postJson("/api/v1/rankings/{$target->id}/rules", [
            'result_key' => 'champion',
            'points' => 120,
        ], $this->keycloakAuthHeaders(['admin']))
            ->assertUnprocessable();
    }

    public function test_cross_type_copy_is_rejected(): void
    {
        $this->seed(RankingSeeder::class);
        $source = Ranking::query()->where('name', RankingSeeder::SINGLES_NAME)->firstOrFail();

        $this->postJson(
            '/api/v1/rankings',
            $this->validPayload([
                'name' => 'Ranking Dobles 2027',
                'competition_type' => CompetitionType::Doubles->value,
                'copy_rules_from_ranking_id' => $source->id,
            ]),
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.copy_rules_from_ranking_id.0', CopyRankingRulesAction::CROSS_TYPE_MESSAGE);
    }

    public function test_organizer_cannot_update_or_delete_ranking(): void
    {
        $ranking = RankingTestSetup::ranking();

        $this->patchJson(
            "/api/v1/rankings/{$ranking->id}",
            ['name' => 'Otro'],
            $this->keycloakAuthHeaders(['organizer']),
        )->assertForbidden();

        $this->deleteJson(
            "/api/v1/rankings/{$ranking->id}",
            [],
            $this->keycloakAuthHeaders(['organizer']),
        )->assertForbidden();
    }

    public function test_update_empty_ranking_allows_structural_fields(): void
    {
        $ranking = RankingTestSetup::ranking();
        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);
        $primera = Category::query()->where('slug', 'primera')->firstOrFail();

        $data = $this->patchJson(
            "/api/v1/rankings/{$ranking->id}",
            [
                'name' => 'Ranking Dobles Club',
                'competition_type' => CompetitionType::Doubles->value,
                'category_id' => $primera->id,
                'season' => 'Apertura 2027',
                'starts_at' => '2027-03-01',
                'ends_at' => '2027-08-31',
                'active' => true,
            ],
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertOk()
            ->json('data');

        $this->assertSame('Ranking Dobles Club', $data['name']);
        $this->assertSame('doubles', $data['competition_type']);
        $this->assertSame($primera->id, $data['category']['id']);
        $this->assertSame('Apertura 2027', $data['season']);
        $this->assertSame('2027-03-01', $data['starts_at']);
        $this->assertSame('2027-08-31', $data['ends_at']);
        $this->assertTrue($data['active']);
        $this->assertCount(1, $data['rules']);
    }

    public function test_update_used_ranking_freezes_structural_fields(): void
    {
        $ranking = RankingTestSetup::ranking();
        $rule = RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);
        $this->attachTransaction($ranking, $rule);

        $this->patchJson(
            "/api/v1/rankings/{$ranking->id}",
            ['competition_type' => CompetitionType::Doubles->value],
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.ranking.0', RankingMutationGuard::STRUCTURAL_LOCKED_MESSAGE);

        $this->patchJson(
            "/api/v1/rankings/{$ranking->id}",
            ['season' => '2028'],
            $this->keycloakAuthHeaders(['admin']),
        )->assertUnprocessable();

        $this->patchJson(
            "/api/v1/rankings/{$ranking->id}",
            ['starts_at' => '2028-01-01'],
            $this->keycloakAuthHeaders(['admin']),
        )->assertUnprocessable();

        $this->patchJson(
            "/api/v1/rankings/{$ranking->id}",
            [
                'name' => 'Ranking Singles 2026 corregido',
                'competition_type' => CompetitionType::Singles->value,
            ],
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertOk()
            ->assertJsonPath('data.name', 'Ranking Singles 2026 corregido')
            ->assertJsonPath('data.competition_type', 'singles');
    }

    public function test_deactivate_and_reactivate_used_ranking_does_not_touch_ledger(): void
    {
        $ranking = RankingTestSetup::ranking();
        $rule = RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);
        $transaction = $this->attachTransaction($ranking, $rule);

        RankingStanding::query()->create([
            'ranking_id' => $ranking->id,
            'player_id' => $transaction->player_id,
            'points_total' => 100,
            'events_count' => 1,
        ]);

        $this->patchJson(
            "/api/v1/rankings/{$ranking->id}",
            ['active' => false],
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertSame(1, $ranking->transactions()->count());
        $this->assertSame(1, $ranking->standings()->count());

        $this->patchJson(
            "/api/v1/rankings/{$ranking->id}",
            ['active' => true],
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertOk()
            ->assertJsonPath('data.active', true);

        $this->assertSame(1, $ranking->transactions()->count());
        $this->assertSame(1, $ranking->standings()->count());
    }

    public function test_delete_empty_ranking_cascades_rules(): void
    {
        $ranking = RankingTestSetup::ranking();
        $rule = RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);

        $this->deleteJson(
            "/api/v1/rankings/{$ranking->id}",
            [],
            $this->keycloakAuthHeaders(['admin']),
        )->assertNoContent();

        $this->assertDatabaseMissing('rankings', ['id' => $ranking->id]);
        $this->assertDatabaseMissing('ranking_rules', ['id' => $rule->id]);
    }

    public function test_delete_used_ranking_is_blocked(): void
    {
        $ranking = RankingTestSetup::ranking();
        $rule = RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);
        $this->attachTransaction($ranking, $rule);

        $this->deleteJson(
            "/api/v1/rankings/{$ranking->id}",
            [],
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.ranking.0', DeleteRankingAction::USED_RANKING_MESSAGE);

        $this->assertDatabaseHas('rankings', ['id' => $ranking->id]);
        $this->assertSame(1, $ranking->transactions()->count());
    }

    public function test_overlapping_rankings_are_allowed(): void
    {
        $payload = $this->validPayload([
            'name' => 'Ranking Oficial',
            'starts_at' => '2027-01-01',
            'ends_at' => '2027-12-31',
        ]);

        $this->postJson('/api/v1/rankings', $payload, $this->keycloakAuthHeaders(['admin']))
            ->assertCreated();

        $this->postJson(
            '/api/v1/rankings',
            array_merge($payload, ['name' => 'Ranking Copa Club']),
            $this->keycloakAuthHeaders(['admin']),
        )->assertCreated();

        $this->assertSame(
            2,
            Ranking::query()
                ->where('competition_type', CompetitionType::Singles)
                ->where('season', '2027')
                ->count(),
        );
    }

    public function test_ranking_audit_actions(): void
    {
        Activity::query()->delete();

        $created = $this->postJson(
            '/api/v1/rankings',
            $this->validPayload(),
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertCreated()
            ->json('data');

        $this->assertSame(
            1,
            Activity::query()->where('description', AuditAction::RANKING_CREATED->value)->count(),
        );

        RankingTestSetup::rule(Ranking::query()->findOrFail($created['id']), [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);

        Activity::query()->delete();

        $this->patchJson(
            "/api/v1/rankings/{$created['id']}",
            ['name' => 'Ranking actualizado'],
            $this->keycloakAuthHeaders(['admin']),
        )->assertOk();

        $this->assertSame(
            1,
            Activity::query()->where('description', AuditAction::RANKING_UPDATED->value)->count(),
        );

        Activity::query()->delete();

        $this->patchJson(
            "/api/v1/rankings/{$created['id']}",
            ['active' => true],
            $this->keycloakAuthHeaders(['admin']),
        )->assertOk();

        $this->assertSame(
            1,
            Activity::query()->where('description', AuditAction::RANKING_ACTIVATED->value)->count(),
        );
        $this->assertSame(
            0,
            Activity::query()->where('description', AuditAction::RANKING_UPDATED->value)->count(),
        );

        Activity::query()->delete();

        $this->patchJson(
            "/api/v1/rankings/{$created['id']}",
            ['active' => false],
            $this->keycloakAuthHeaders(['admin']),
        )->assertOk();

        $this->assertSame(
            1,
            Activity::query()->where('description', AuditAction::RANKING_DEACTIVATED->value)->count(),
        );

        Activity::query()->delete();

        $this->deleteJson(
            "/api/v1/rankings/{$created['id']}",
            [],
            $this->keycloakAuthHeaders(['admin']),
        )->assertNoContent();

        $this->assertSame(
            1,
            Activity::query()->where('description', AuditAction::RANKING_DELETED->value)->count(),
        );
    }

    public function test_index_exposes_has_history_without_rules_locked(): void
    {
        $ranking = RankingTestSetup::ranking();
        $rule = RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);

        $index = $this->getJson('/api/v1/rankings')->assertOk()->json('data');
        $row = collect($index)->firstWhere('id', $ranking->id);

        $this->assertFalse($row['has_history']);
        $this->assertArrayNotHasKey('rules_locked', $row);
        $this->assertArrayNotHasKey('rules', $row);

        $this->attachTransaction($ranking, $rule);

        $index = $this->getJson('/api/v1/rankings')->assertOk()->json('data');
        $row = collect($index)->firstWhere('id', $ranking->id);

        $this->assertTrue($row['has_history']);
        $this->assertArrayNotHasKey('rules_locked', $row);
    }

    private function attachTransaction(Ranking $ranking, ?RankingRule $rule): RankingTransaction
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(1);

        return RankingTransaction::query()->create([
            'ranking_id' => $ranking->id,
            'competition_id' => $competition->id,
            'player_id' => $players[0]->id,
            'ranking_rule_id' => $rule?->id,
            'competition_type' => $ranking->competition_type,
            'category_id' => $competition->category_id,
            'position' => 1,
            'position_range_end' => 1,
            'source' => CompetitionFinalStandingSource::Final,
            'points' => 100,
            'player_display_name_snapshot' => 'Jugador Test',
            'competition_name_snapshot' => $competition->name,
            'ranking_rule_name_snapshot' => $rule?->name,
        ]);
    }
}
