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
