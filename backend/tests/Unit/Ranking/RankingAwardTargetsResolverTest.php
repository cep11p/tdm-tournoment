<?php

namespace Tests\Unit\Ranking;

use App\Enums\CompetitionType;
use App\Models\Category;
use App\Support\Ranking\RankingAwardTargetsResolver;
use Illuminate\Support\Carbon;
use Tests\Support\RankingTestSetup;
use Tests\TestCase;

class RankingAwardTargetsResolverTest extends TestCase
{
    public function test_active_and_scope_is_included(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles);
        $competition = $context->createKnockoutDirectCompetition();

        $ids = app(RankingAwardTargetsResolver::class)
            ->resolve($competition, [])
            ->pluck('id')
            ->all();

        $this->assertSame([$ranking->id], $ids);
    }

    public function test_inactive_without_previous_award_is_excluded(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        RankingTestSetup::ranking(CompetitionType::Singles, ['active' => false]);
        $competition = $context->createKnockoutDirectCompetition();

        $this->assertCount(
            0,
            app(RankingAwardTargetsResolver::class)->resolve($competition, []),
        );
    }

    public function test_inactive_with_previous_award_and_scope_is_included(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, ['active' => false]);
        $competition = $context->createKnockoutDirectCompetition();

        $ids = app(RankingAwardTargetsResolver::class)
            ->resolve($competition, [$ranking->id])
            ->pluck('id')
            ->all();

        $this->assertSame([$ranking->id], $ids);
    }

    public function test_inactive_with_previous_award_and_category_mismatch_is_excluded(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $segunda = Category::query()->where('slug', 'segunda')->firstOrFail();
        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, [
            'active' => false,
            'category_id' => $segunda->id,
        ]);
        $competition = $context->createKnockoutDirectCompetition();

        $this->assertCount(
            0,
            app(RankingAwardTargetsResolver::class)->resolve($competition, [$ranking->id]),
        );
    }

    public function test_inactive_with_previous_award_and_date_mismatch_is_excluded(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, [
            'active' => false,
            'starts_at' => '2025-01-01',
            'ends_at' => '2025-12-31',
        ]);
        $competition = $context->createKnockoutDirectCompetition();
        $competition->tournament->update(['start_date' => Carbon::parse('2026-06-01')]);

        $this->assertCount(
            0,
            app(RankingAwardTargetsResolver::class)->resolve(
                $competition->fresh('tournament'),
                [$ranking->id],
            ),
        );
    }

    public function test_wrong_competition_type_is_excluded(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Doubles, ['active' => false]);
        $competition = $context->createKnockoutDirectCompetition();

        $this->assertCount(
            0,
            app(RankingAwardTargetsResolver::class)->resolve($competition, [$ranking->id]),
        );
    }

    public function test_zero_point_previous_membership_counts(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, ['active' => false]);
        $competition = $context->createKnockoutDirectCompetition();

        $ids = app(RankingAwardTargetsResolver::class)
            ->resolve($competition, [$ranking->id])
            ->pluck('id')
            ->all();

        $this->assertSame([$ranking->id], $ids);
    }

    public function test_active_and_previously_awarded_appears_once(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles);
        $competition = $context->createKnockoutDirectCompetition();

        $ids = app(RankingAwardTargetsResolver::class)
            ->resolve($competition, [$ranking->id, $ranking->id])
            ->pluck('id')
            ->all();

        $this->assertSame([$ranking->id], $ids);
    }

    public function test_team_competition_returns_empty(): void
    {
        $context = $this->tournamentContext();
        $ranking = RankingTestSetup::ranking(CompetitionType::Singles);
        $competition = $context->createTeamCompetition();

        $this->assertCount(
            0,
            app(RankingAwardTargetsResolver::class)->resolve($competition, [$ranking->id]),
        );
    }

    public function test_targets_are_ordered_by_id(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $lowerId = RankingTestSetup::ranking(CompetitionType::Singles, ['name' => 'Lower']);
        $higherId = RankingTestSetup::ranking(CompetitionType::Singles, [
            'name' => 'Higher',
            'active' => false,
        ]);
        $competition = $context->createKnockoutDirectCompetition();

        $ids = app(RankingAwardTargetsResolver::class)
            ->resolve($competition, [$higherId->id])
            ->pluck('id')
            ->all();

        $this->assertTrue($lowerId->id < $higherId->id);
        $this->assertSame([$lowerId->id, $higherId->id], $ids);
    }
}
