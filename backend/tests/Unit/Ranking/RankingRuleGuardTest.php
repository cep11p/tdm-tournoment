<?php

namespace Tests\Unit\Ranking;

use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionType;
use App\Models\Ranking;
use App\Support\Ranking\RankingTypeGuard;
use Illuminate\Validation\ValidationException;
use Tests\Support\RankingTestSetup;
use Tests\TestCase;

class RankingRuleGuardTest extends TestCase
{
    public function test_rejects_position_on_non_final_sources(): void
    {
        $ranking = RankingTestSetup::ranking();

        try {
            RankingTestSetup::rule($ranking, [
                'source' => CompetitionFinalStandingSource::Quarterfinal,
                'position' => 5,
                'points' => 25,
            ]);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('position', $exception->errors());
        }
    }

    public function test_rejects_final_position_other_than_one_or_two(): void
    {
        $ranking = RankingTestSetup::ranking();

        try {
            RankingTestSetup::rule($ranking, [
                'source' => CompetitionFinalStandingSource::Final,
                'position' => 5,
                'points' => 100,
            ]);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('position', $exception->errors());
        }
    }

    public function test_rejects_third_place_playoff_position_other_than_three_or_four(): void
    {
        $ranking = RankingTestSetup::ranking();

        try {
            RankingTestSetup::rule($ranking, [
                'source' => CompetitionFinalStandingSource::ThirdPlacePlayoff,
                'position' => 2,
                'points' => 50,
            ]);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('position', $exception->errors());
        }
    }

    public function test_accepts_semifinal_with_null_position(): void
    {
        $ranking = RankingTestSetup::ranking();

        $rule = RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Semifinal,
            'position' => null,
            'points' => 45,
        ]);

        $this->assertNull($rule->position);
        $this->assertSame(CompetitionFinalStandingSource::Semifinal, $rule->source);
    }

    public function test_rejects_not_in_draw_as_ranking_rule_source(): void
    {
        $ranking = RankingTestSetup::ranking();

        try {
            RankingTestSetup::rule($ranking, [
                'source' => CompetitionFinalStandingSource::NotInDraw,
                'position' => null,
                'points' => 0,
            ]);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source', $exception->errors());
        }
    }

    public function test_rejects_team_ranking(): void
    {
        try {
            Ranking::query()->create([
                'name' => 'Ranking Team',
                'competition_type' => CompetitionType::Team,
                'season' => '2026',
                'active' => true,
            ]);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame(
                RankingTypeGuard::TEAM_UNSUPPORTED_MESSAGE,
                $exception->errors()['competition_type'][0],
            );
        }
    }
}
