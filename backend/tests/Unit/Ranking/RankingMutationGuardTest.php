<?php

namespace Tests\Unit\Ranking;

use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionType;
use App\Models\Ranking;
use App\Models\RankingRule;
use App\Models\RankingTransaction;
use App\Support\Ranking\RankingMutationGuard;
use Illuminate\Validation\ValidationException;
use Tests\Support\RankingTestSetup;
use Tests\TestCase;

class RankingMutationGuardTest extends TestCase
{
    public function test_empty_ranking_can_change_structural_fields(): void
    {
        $ranking = RankingTestSetup::ranking();
        $ranking->competition_type = CompetitionType::Doubles;
        $ranking->season = '2028';

        RankingMutationGuard::assertStructuralMutable($ranking);

        $this->assertTrue(RankingMutationGuard::hasStructuralChanges($ranking));
        $this->assertFalse(RankingMutationGuard::hasHistory($ranking));
    }

    public function test_used_ranking_blocks_real_structural_changes(): void
    {
        $ranking = RankingTestSetup::ranking();
        $rule = RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);
        $this->attachTransaction($ranking, $rule);

        $ranking->season = '2028';

        try {
            RankingMutationGuard::assertStructuralMutable($ranking);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame(
                [RankingMutationGuard::STRUCTURAL_LOCKED_MESSAGE],
                $exception->errors()['ranking'],
            );
        }
    }

    public function test_used_ranking_allows_unchanged_structural_payload(): void
    {
        $ranking = RankingTestSetup::ranking();
        $rule = RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);
        $this->attachTransaction($ranking, $rule);

        $ranking->fill([
            'name' => 'Nombre corregido',
            'competition_type' => CompetitionType::Singles->value,
            'season' => '2026',
        ]);

        RankingMutationGuard::assertStructuralMutable($ranking);

        $this->assertFalse(RankingMutationGuard::hasStructuralChanges($ranking));
    }

    public function test_cannot_activate_without_active_rules(): void
    {
        $ranking = RankingTestSetup::ranking(overrides: ['active' => false]);

        try {
            RankingMutationGuard::assertCanActivate($ranking);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame(
                [RankingMutationGuard::ACTIVE_WITHOUT_RULES_MESSAGE],
                $exception->errors()['active'],
            );
        }
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
