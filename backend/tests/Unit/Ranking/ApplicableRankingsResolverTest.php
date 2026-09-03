<?php

namespace Tests\Unit\Ranking;

use App\Enums\CompetitionType;
use App\Models\Category;
use App\Support\Ranking\ApplicableRankingsResolver;
use Illuminate\Support\Carbon;
use Tests\Support\RankingTestSetup;
use Tests\TestCase;

class ApplicableRankingsResolverTest extends TestCase
{
    public function test_team_competition_returns_empty(): void
    {
        $context = $this->tournamentContext();
        RankingTestSetup::ranking(CompetitionType::Singles);
        $competition = $context->createTeamCompetition();

        $applicable = app(ApplicableRankingsResolver::class)->resolve($competition);

        $this->assertCount(0, $applicable);
    }

    public function test_global_ranking_matches_any_category(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $global = RankingTestSetup::ranking(CompetitionType::Singles, ['name' => 'Global']);
        $competition = $context->createKnockoutDirectCompetition();

        $ids = app(ApplicableRankingsResolver::class)->resolve($competition)->pluck('id')->all();

        $this->assertContains($global->id, $ids);
    }

    public function test_category_ranking_only_matches_same_category(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $primera = Category::query()->where('slug', 'primera')->firstOrFail();
        $segunda = Category::query()->where('slug', 'segunda')->firstOrFail();
        $primeraRanking = RankingTestSetup::ranking(CompetitionType::Singles, [
            'name' => 'Primera',
            'category_id' => $primera->id,
        ]);
        $segundaRanking = RankingTestSetup::ranking(CompetitionType::Singles, [
            'name' => 'Segunda',
            'category_id' => $segunda->id,
        ]);

        $competition = $context->createKnockoutDirectCompetition();
        $ids = app(ApplicableRankingsResolver::class)->resolve($competition)->pluck('id')->all();

        $this->assertContains($primeraRanking->id, $ids);
        $this->assertNotContains($segundaRanking->id, $ids);
    }

    public function test_date_window_uses_tournament_start_date(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $inside = RankingTestSetup::ranking(CompetitionType::Singles, [
            'name' => 'Inside',
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);
        $outside = RankingTestSetup::ranking(CompetitionType::Singles, [
            'name' => 'Outside',
            'starts_at' => '2025-01-01',
            'ends_at' => '2025-12-31',
        ]);

        $competition = $context->createKnockoutDirectCompetition();
        $competition->tournament->update(['start_date' => Carbon::parse('2026-06-01')]);

        $ids = app(ApplicableRankingsResolver::class)->resolve($competition->fresh('tournament'))->pluck('id')->all();

        $this->assertContains($inside->id, $ids);
        $this->assertNotContains($outside->id, $ids);
    }

    public function test_inactive_ranking_is_excluded(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        RankingTestSetup::ranking(CompetitionType::Singles, ['active' => false]);
        $competition = $context->createKnockoutDirectCompetition();

        $this->assertCount(0, app(ApplicableRankingsResolver::class)->resolve($competition));
    }
}
