<?php

namespace Tests\Unit\Ranking;

use App\Enums\CompetitionType;
use App\Models\Category;
use App\Support\Ranking\RankingScopeMatcher;
use Illuminate\Support\Carbon;
use Tests\Support\RankingTestSetup;
use Tests\TestCase;

class RankingScopeMatcherTest extends TestCase
{
    public function test_global_ranking_matches_any_category(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, ['category_id' => null]);
        $competition = $context->createKnockoutDirectCompetition();

        $this->assertTrue(app(RankingScopeMatcher::class)->matches($ranking, $competition));
    }

    public function test_specific_category_matches_same_category(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $primera = Category::query()->where('slug', 'primera')->firstOrFail();
        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, [
            'category_id' => $primera->id,
        ]);
        $competition = $context->createKnockoutDirectCompetition();

        $this->assertTrue(app(RankingScopeMatcher::class)->matches($ranking, $competition));
    }

    public function test_specific_category_rejects_other_category(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $segunda = Category::query()->where('slug', 'segunda')->firstOrFail();
        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, [
            'category_id' => $segunda->id,
        ]);
        $competition = $context->createKnockoutDirectCompetition();

        $this->assertFalse(app(RankingScopeMatcher::class)->matches($ranking, $competition));
    }

    public function test_open_date_window_matches_any_tournament_date(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, [
            'starts_at' => null,
            'ends_at' => null,
        ]);
        $competition = $context->createKnockoutDirectCompetition();

        $this->assertTrue(app(RankingScopeMatcher::class)->matches($ranking, $competition));
    }

    public function test_date_window_includes_tournament_start_date(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, [
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);
        $competition = $context->createKnockoutDirectCompetition();
        $competition->tournament->update(['start_date' => Carbon::parse('2026-06-01')]);

        $this->assertTrue(app(RankingScopeMatcher::class)->matches(
            $ranking,
            $competition->fresh('tournament'),
        ));
    }

    public function test_date_window_rejects_before_starts_at(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, [
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);
        $competition = $context->createKnockoutDirectCompetition();
        $competition->tournament->update(['start_date' => Carbon::parse('2025-12-31')]);

        $this->assertFalse(app(RankingScopeMatcher::class)->matches(
            $ranking,
            $competition->fresh('tournament'),
        ));
    }

    public function test_date_window_rejects_after_ends_at(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, [
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);
        $competition = $context->createKnockoutDirectCompetition();
        $competition->tournament->update(['start_date' => Carbon::parse('2027-01-01')]);

        $this->assertFalse(app(RankingScopeMatcher::class)->matches(
            $ranking,
            $competition->fresh('tournament'),
        ));
    }

    public function test_wrong_competition_type_does_not_match(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Doubles);
        $competition = $context->createKnockoutDirectCompetition();

        $this->assertFalse(app(RankingScopeMatcher::class)->matches($ranking, $competition));
    }

    public function test_inactive_does_not_affect_scope(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, ['active' => false]);
        $competition = $context->createKnockoutDirectCompetition();

        $this->assertTrue(app(RankingScopeMatcher::class)->matches($ranking, $competition));
    }
}
