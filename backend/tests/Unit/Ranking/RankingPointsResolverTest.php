<?php

namespace Tests\Unit\Ranking;

use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionType;
use App\Models\Category;
use App\Models\CompetitionFinalStanding;
use App\Models\Ranking;
use App\Models\RankingRule;
use App\Support\Ranking\RankingPointsResolver;
use Tests\Support\RankingTestSetup;
use Tests\TestCase;

class RankingPointsResolverTest extends TestCase
{
    public function test_final_one_and_two(): void
    {
        [$ranking, $champion, $runnerUp] = $this->singlesFinalStandings();

        RankingTestSetup::rule($ranking, [
            'name' => 'Campeón',
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
            'priority' => 20,
        ]);
        RankingTestSetup::rule($ranking, [
            'name' => 'Subcampeón',
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 2,
            'points' => 70,
            'priority' => 19,
        ]);

        $resolver = app(RankingPointsResolver::class);
        $ranking->load('rules');

        $championMatch = $resolver->resolve($ranking, $champion, $ranking->rules);
        $runnerUpMatch = $resolver->resolve($ranking, $runnerUp, $ranking->rules);

        $this->assertSame(100, $championMatch->points);
        $this->assertSame('Campeón', $championMatch->rule?->name);
        $this->assertSame(70, $runnerUpMatch->points);
        $this->assertSame('Subcampeón', $runnerUpMatch->rule?->name);
    }

    public function test_shared_semifinal_uses_null_position(): void
    {
        [$ranking, $standing] = $this->singlesChampionStanding();
        $standing->source = CompetitionFinalStandingSource::Semifinal;
        $standing->position = 3;
        $standing->position_range_end = 4;

        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Semifinal,
            'position' => null,
            'points' => 45,
        ]);

        $match = app(RankingPointsResolver::class)->resolve($ranking->fresh('rules'), $standing, $ranking->fresh('rules')->rules);

        $this->assertSame(45, $match->points);
        $this->assertNotNull($match->rule);
    }

    public function test_playoff_third_and_fourth(): void
    {
        [$ranking, $standing] = $this->singlesChampionStanding();

        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::ThirdPlacePlayoff,
            'position' => 3,
            'points' => 50,
            'priority' => 20,
        ]);
        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::ThirdPlacePlayoff,
            'position' => 4,
            'points' => 40,
            'priority' => 19,
        ]);

        $ranking->load('rules');
        $resolver = app(RankingPointsResolver::class);

        $standing->source = CompetitionFinalStandingSource::ThirdPlacePlayoff;
        $standing->position = 3;
        $this->assertSame(50, $resolver->resolve($ranking, $standing, $ranking->rules)->points);

        $standing->position = 4;
        $this->assertSame(40, $resolver->resolve($ranking, $standing, $ranking->rules)->points);
    }

    public function test_quarterfinal_and_group_stage_are_flat(): void
    {
        [$ranking, $standing] = $this->singlesChampionStanding();

        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Quarterfinal,
            'position' => null,
            'points' => 25,
        ]);
        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::GroupStage,
            'position' => null,
            'points' => 2,
        ]);

        $ranking->load('rules');
        $resolver = app(RankingPointsResolver::class);

        $standing->source = CompetitionFinalStandingSource::Quarterfinal;
        $standing->position = 5;
        $standing->position_range_end = 8;
        $this->assertSame(25, $resolver->resolve($ranking, $standing, $ranking->rules)->points);

        $standing->source = CompetitionFinalStandingSource::GroupStage;
        $standing->position = 9;
        $standing->position_range_end = 10;
        $this->assertSame(2, $resolver->resolve($ranking, $standing, $ranking->rules)->points);
    }

    public function test_higher_priority_wins_when_duplicates_are_passed_in_memory(): void
    {
        [$ranking, $standing] = $this->singlesChampionStanding();

        $low = new RankingRule([
            'id' => 1,
            'ranking_id' => $ranking->id,
            'name' => 'Baja',
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 10,
            'priority' => 1,
            'active' => true,
        ]);
        $high = new RankingRule([
            'id' => 2,
            'ranking_id' => $ranking->id,
            'name' => 'Alta',
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 99,
            'priority' => 50,
            'active' => true,
        ]);

        $match = app(RankingPointsResolver::class)->resolve($ranking, $standing, [$low, $high]);

        $this->assertSame(99, $match->points);
        $this->assertSame('Alta', $match->rule?->name);
    }

    public function test_inactive_rule_is_ignored(): void
    {
        [$ranking, $standing] = $this->singlesChampionStanding();

        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
            'active' => false,
        ]);

        $match = app(RankingPointsResolver::class)->resolve($ranking->fresh('rules'), $standing, $ranking->fresh('rules')->rules);

        $this->assertNull($match->rule);
        $this->assertSame(0, $match->points);
    }

    public function test_wrong_type_does_not_match(): void
    {
        [$ranking, $standing] = $this->singlesChampionStanding();
        $doublesRanking = RankingTestSetup::ranking(CompetitionType::Doubles);
        RankingTestSetup::rule($doublesRanking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);

        $match = app(RankingPointsResolver::class)->resolve(
            $doublesRanking->fresh('rules'),
            $standing,
            $doublesRanking->fresh('rules')->rules,
        );

        $this->assertNull($match->rule);
        $this->assertSame(0, $match->points);
        $this->assertSame(CompetitionType::Singles, $ranking->competition_type);
    }

    public function test_wrong_category_does_not_match(): void
    {
        [$ranking, $standing] = $this->singlesChampionStanding();
        $segunda = Category::query()->where('slug', 'segunda')->firstOrFail();
        $ranking->update(['category_id' => $segunda->id]);
        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);

        $match = app(RankingPointsResolver::class)->resolve($ranking->fresh('rules'), $standing, $ranking->fresh('rules')->rules);

        $this->assertNull($match->rule);
        $this->assertSame(0, $match->points);
    }

    public function test_no_rule_returns_zero_without_match(): void
    {
        [$ranking, $standing] = $this->singlesChampionStanding();
        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::GroupStage,
            'position' => null,
            'points' => 2,
        ]);

        $match = app(RankingPointsResolver::class)->resolve($ranking->fresh('rules'), $standing, $ranking->fresh('rules')->rules);

        $this->assertNull($match->rule);
        $this->assertSame(0, $match->points);
    }

    public function test_same_priority_is_deterministic_by_id(): void
    {
        [$ranking, $standing] = $this->singlesChampionStanding();

        $first = new RankingRule([
            'id' => 10,
            'ranking_id' => $ranking->id,
            'name' => 'Primera',
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 11,
            'priority' => 5,
            'active' => true,
        ]);
        $second = new RankingRule([
            'id' => 20,
            'ranking_id' => $ranking->id,
            'name' => 'Segunda',
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 22,
            'priority' => 5,
            'active' => true,
        ]);

        $match = app(RankingPointsResolver::class)->resolve($ranking, $standing, [$first, $second]);

        $this->assertSame(10, $match->rule?->id);
        $this->assertSame(11, $match->points);
    }

    /**
     * @return array{0: Ranking, 1: CompetitionFinalStanding, 2: CompetitionFinalStanding}
     */
    private function singlesFinalStandings(): array
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $champion = $competition->fresh()->finalStandings()->where('position', 1)->firstOrFail();
        $runnerUp = $competition->fresh()->finalStandings()->where('position', 2)->firstOrFail();

        return [RankingTestSetup::ranking(), $champion, $runnerUp];
    }

    /**
     * @return array{0: Ranking, 1: CompetitionFinalStanding}
     */
    private function singlesChampionStanding(): array
    {
        [$ranking, $champion] = $this->singlesFinalStandings();

        return [$ranking, $champion];
    }
}
