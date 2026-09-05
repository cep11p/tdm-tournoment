<?php

namespace Tests\Unit\Ranking;

use App\Enums\CompetitionFinalStandingSource;
use App\Support\Ranking\RankingRuleCatalog;
use Tests\TestCase;

class RankingRuleCatalogTest extends TestCase
{
    public function test_champion_maps_to_final_position_one(): void
    {
        $definition = RankingRuleCatalog::find(RankingRuleCatalog::CHAMPION);

        $this->assertNotNull($definition);
        $this->assertSame('Campeón', $definition['name']);
        $this->assertSame(CompetitionFinalStandingSource::Final, $definition['source']);
        $this->assertSame(1, $definition['position']);
    }

    public function test_key_for_resolves_source_and_position(): void
    {
        $this->assertSame(
            RankingRuleCatalog::CHAMPION,
            RankingRuleCatalog::keyFor(CompetitionFinalStandingSource::Final, 1),
        );
        $this->assertSame(
            RankingRuleCatalog::SEMIFINAL,
            RankingRuleCatalog::keyFor(CompetitionFinalStandingSource::Semifinal, null),
        );
        $this->assertNull(RankingRuleCatalog::keyFor(CompetitionFinalStandingSource::Final, 5));
    }
}
