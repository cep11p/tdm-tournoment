<?php

namespace Tests\Support;

use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionType;
use App\Models\Ranking;
use App\Models\RankingRule;

final class RankingTestSetup
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function ranking(CompetitionType $type = CompetitionType::Singles, array $overrides = []): Ranking
    {
        return Ranking::query()->create(array_merge([
            'name' => 'Ranking Test '.$type->value.' '.uniqid(),
            'competition_type' => $type,
            'category_id' => null,
            'season' => '2026',
            'active' => true,
            'starts_at' => null,
            'ends_at' => null,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function rule(Ranking $ranking, array $overrides = []): RankingRule
    {
        return RankingRule::query()->create(array_merge([
            'ranking_id' => $ranking->id,
            'name' => 'Regla test',
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
            'priority' => 10,
            'active' => true,
        ], $overrides));
    }
}
