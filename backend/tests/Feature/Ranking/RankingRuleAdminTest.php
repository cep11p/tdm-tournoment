<?php

namespace Tests\Feature\Ranking;

use App\Actions\Ranking\CreateRankingRuleAction;
use App\Actions\Ranking\DeleteRankingRuleAction;
use App\Enums\AuditAction;
use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionType;
use App\Models\Ranking;
use App\Models\RankingRule;
use App\Models\RankingTransaction;
use App\Support\Ranking\RankingRulesMutationGuard;
use Database\Seeders\RankingSeeder;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RankingTestSetup;
use Tests\TestCase;

class RankingRuleAdminTest extends TestCase
{
    public function test_guest_cannot_create_rule(): void
    {
        $ranking = RankingTestSetup::ranking();

        $this->postJson("/api/v1/rankings/{$ranking->id}/rules", [
            'result_key' => 'champion',
            'points' => 100,
        ])
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'No autenticado.',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_organizer_cannot_create_rule(): void
    {
        $ranking = RankingTestSetup::ranking();

        $this->postJson("/api/v1/rankings/{$ranking->id}/rules", [
            'result_key' => 'champion',
            'points' => 100,
        ], $this->keycloakAuthHeaders(['organizer']))
            ->assertForbidden()
            ->assertJson([
                'message' => 'No autorizado.',
                'code' => 'forbidden',
            ]);
    }

    public function test_scorekeeper_cannot_create_rule(): void
    {
        $ranking = RankingTestSetup::ranking();

        $this->postJson("/api/v1/rankings/{$ranking->id}/rules", [
            'result_key' => 'champion',
            'points' => 100,
        ], $this->keycloakAuthHeaders(['scorekeeper']))
            ->assertForbidden();
    }

    public function test_admin_can_create_champion_rule_from_result_key(): void
    {
        $ranking = RankingTestSetup::ranking();

        $data = $this->postJson("/api/v1/rankings/{$ranking->id}/rules", [
            'result_key' => 'champion',
            'points' => 100,
            'active' => true,
        ], $this->keycloakAuthHeaders(['admin']))
            ->assertCreated()
            ->json('data');

        $this->assertSame('champion', $data['result_key']);
        $this->assertSame('Campeón', $data['name']);
        $this->assertSame('final', $data['source']);
        $this->assertSame(1, $data['position']);
        $this->assertSame(100, $data['points']);
        $this->assertTrue($data['active']);
        $this->assertSame(0, $data['priority']);

        $activity = Activity::query()
            ->where('description', AuditAction::RANKING_RULE_CREATED->value)
            ->sole();

        $this->assertSame('rankings', $activity->log_name);
        $this->assertSame(RankingRule::class, $activity->subject_type);
        $this->assertSame('champion', data_get($activity->properties, 'new.result_key'));
        $this->assertSame(100, data_get($activity->properties, 'new.points'));
    }

    public function test_unknown_result_key_is_rejected(): void
    {
        $ranking = RankingTestSetup::ranking();

        $this->postJson("/api/v1/rankings/{$ranking->id}/rules", [
            'result_key' => 'unknown_result',
            'points' => 10,
        ], $this->keycloakAuthHeaders(['admin']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['result_key']);
    }

    public function test_structural_fields_are_prohibited_on_store(): void
    {
        $ranking = RankingTestSetup::ranking();

        $this->postJson("/api/v1/rankings/{$ranking->id}/rules", [
            'result_key' => 'champion',
            'points' => 100,
            'source' => 'semifinal',
            'position' => 3,
            'name' => 'Otro',
            'priority' => 99,
        ], $this->keycloakAuthHeaders(['admin']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source', 'position', 'name', 'priority']);
    }

    public function test_duplicate_semifinal_with_null_position_is_rejected(): void
    {
        $ranking = RankingTestSetup::ranking();
        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Semifinal,
            'position' => null,
            'points' => 45,
        ]);

        $this->postJson("/api/v1/rankings/{$ranking->id}/rules", [
            'result_key' => 'semifinal',
            'points' => 40,
        ], $this->keycloakAuthHeaders(['admin']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.result_key.0', CreateRankingRuleAction::DUPLICATE_MESSAGE);

        $this->assertSame(
            1,
            RankingRule::query()
                ->where('ranking_id', $ranking->id)
                ->where('source', CompetitionFinalStandingSource::Semifinal)
                ->whereNull('position')
                ->count(),
        );
    }

    public function test_duplicate_champion_is_rejected(): void
    {
        $ranking = RankingTestSetup::ranking();
        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);

        $this->postJson("/api/v1/rankings/{$ranking->id}/rules", [
            'result_key' => 'champion',
            'points' => 120,
        ], $this->keycloakAuthHeaders(['admin']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['result_key']);
    }

    public function test_update_points_and_active_on_empty_ranking(): void
    {
        $ranking = RankingTestSetup::ranking();
        $rule = RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
            'active' => true,
        ]);

        $data = $this->patchJson("/api/v1/rankings/{$ranking->id}/rules/{$rule->id}", [
            'points' => 120,
        ], $this->keycloakAuthHeaders(['admin']))
            ->assertOk()
            ->json('data');

        $this->assertSame(120, $data['points']);
        $this->assertTrue($data['active']);
        $this->assertSame('champion', $data['result_key']);

        $updated = $this->patchJson("/api/v1/rankings/{$ranking->id}/rules/{$rule->id}", [
            'active' => false,
        ], $this->keycloakAuthHeaders(['admin']))
            ->assertOk()
            ->json('data');

        $this->assertFalse($updated['active']);
        $this->assertSame(120, $updated['points']);

        $this->assertSame(
            1,
            Activity::query()->where('description', AuditAction::RANKING_RULE_UPDATED->value)->count(),
        );
        $this->assertSame(
            1,
            Activity::query()->where('description', AuditAction::RANKING_RULE_DEACTIVATED->value)->count(),
        );
    }

    public function test_update_rejects_structural_changes(): void
    {
        $ranking = RankingTestSetup::ranking();
        $rule = RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);

        $this->patchJson("/api/v1/rankings/{$ranking->id}/rules/{$rule->id}", [
            'result_key' => 'runner_up',
            'points' => 80,
        ], $this->keycloakAuthHeaders(['admin']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['result_key']);
    }

    public function test_delete_unused_rule_on_empty_ranking(): void
    {
        $ranking = RankingTestSetup::ranking();
        $rule = RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);

        $this->deleteJson(
            "/api/v1/rankings/{$ranking->id}/rules/{$rule->id}",
            [],
            $this->keycloakAuthHeaders(['admin']),
        )->assertNoContent();

        $this->assertDatabaseMissing('ranking_rules', ['id' => $rule->id]);
        $this->assertSame(
            1,
            Activity::query()->where('description', AuditAction::RANKING_RULE_DELETED->value)->count(),
        );
    }

    public function test_nested_rule_from_another_ranking_returns_not_found(): void
    {
        $ranking = RankingTestSetup::ranking();
        $other = RankingTestSetup::ranking(CompetitionType::Doubles);
        $rule = RankingTestSetup::rule($other, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);

        $this->patchJson("/api/v1/rankings/{$ranking->id}/rules/{$rule->id}", [
            'points' => 50,
        ], $this->keycloakAuthHeaders(['admin']))
            ->assertNotFound();
    }

    public function test_freeze_blocks_all_mutations_when_ranking_has_transactions(): void
    {
        $ranking = RankingTestSetup::ranking();
        $rule = RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);
        $this->attachTransaction($ranking, $rule);

        $this->postJson("/api/v1/rankings/{$ranking->id}/rules", [
            'result_key' => 'semifinal',
            'points' => 45,
        ], $this->keycloakAuthHeaders(['admin']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.ranking.0', RankingRulesMutationGuard::LOCKED_MESSAGE);

        $this->patchJson("/api/v1/rankings/{$ranking->id}/rules/{$rule->id}", [
            'points' => 120,
        ], $this->keycloakAuthHeaders(['admin']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.ranking.0', RankingRulesMutationGuard::LOCKED_MESSAGE);

        $this->patchJson("/api/v1/rankings/{$ranking->id}/rules/{$rule->id}", [
            'active' => false,
        ], $this->keycloakAuthHeaders(['admin']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.ranking.0', RankingRulesMutationGuard::LOCKED_MESSAGE);

        $this->deleteJson(
            "/api/v1/rankings/{$ranking->id}/rules/{$rule->id}",
            [],
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.ranking.0', RankingRulesMutationGuard::LOCKED_MESSAGE);

        $this->assertSame(100, (int) $rule->fresh()->points);
        $this->assertTrue((bool) $rule->fresh()->active);
    }

    public function test_delete_used_rule_on_otherwise_empty_ranking_is_rejected(): void
    {
        $ranking = RankingTestSetup::ranking();
        $otherRanking = RankingTestSetup::ranking(CompetitionType::Doubles);
        $rule = RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);
        $this->attachTransaction($otherRanking, $rule);

        $this->deleteJson(
            "/api/v1/rankings/{$ranking->id}/rules/{$rule->id}",
            [],
            $this->keycloakAuthHeaders(['admin']),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.rule.0', DeleteRankingRuleAction::USED_RULE_MESSAGE);
    }

    public function test_show_rules_locked_flag_depends_on_transactions(): void
    {
        $ranking = RankingTestSetup::ranking();
        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);

        $unlocked = $this->getJson("/api/v1/rankings/{$ranking->id}")
            ->assertOk()
            ->json('data');

        $this->assertFalse($unlocked['rules_locked']);
        $this->assertFalse($unlocked['has_history']);
        $this->assertSame('champion', $unlocked['rules'][0]['result_key']);

        $index = $this->getJson('/api/v1/rankings')->assertOk()->json('data');
        $indexRow = collect($index)->firstWhere('id', $ranking->id);
        $this->assertArrayNotHasKey('rules_locked', $indexRow);
        $this->assertFalse($indexRow['has_history']);

        $this->attachTransaction($ranking, RankingRule::query()->where('ranking_id', $ranking->id)->first());

        $locked = $this->getJson("/api/v1/rankings/{$ranking->id}")
            ->assertOk()
            ->json('data');

        $this->assertTrue($locked['rules_locked']);
        $this->assertTrue($locked['has_history']);
    }

    public function test_rule_change_is_frozen_and_final_correction_keeps_original_points(): void
    {
        $this->seed(RankingSeeder::class);
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['admin']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $ranking = Ranking::query()->where('name', RankingSeeder::SINGLES_NAME)->firstOrFail();
        $championRule = RankingRule::query()
            ->where('ranking_id', $ranking->id)
            ->where('source', CompetitionFinalStandingSource::Final)
            ->where('position', 1)
            ->firstOrFail();

        $this->patchJson("/api/v1/rankings/{$ranking->id}/rules/{$championRule->id}", [
            'points' => 120,
        ], $this->keycloakAuthHeaders(['admin']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.ranking.0', RankingRulesMutationGuard::LOCKED_MESSAGE);

        $this->assertSame(100, (int) $championRule->fresh()->points);

        $final = $final->fresh();
        $player1Won = (int) $final->winner_entry_id === (int) $final->entry1_id;
        $context->correctResult($final, 'Corrección de la Final', [
            $player1Won
                ? ['player1_score' => 0, 'player2_score' => 11]
                : ['player1_score' => 11, 'player2_score' => 0],
        ])->assertOk();

        $this->assertSame(70, (int) RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $players[0]->id)
            ->value('points'));
        $this->assertSame(100, (int) RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $players[1]->id)
            ->value('points'));
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
