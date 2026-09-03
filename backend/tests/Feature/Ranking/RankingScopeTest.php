<?php

namespace Tests\Feature\Ranking;

use App\Actions\Ranking\AwardRankingFromFinalStandingsAction;
use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionType;
use App\Models\Category;
use App\Models\RankingTransaction;
use Tests\Support\RankingTestSetup;
use Tests\TestCase;

class RankingScopeTest extends TestCase
{
    public function test_category_specific_and_global_rankings_can_both_apply(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $primera = Category::query()->where('slug', 'primera')->firstOrFail();
        $segunda = Category::query()->where('slug', 'segunda')->firstOrFail();

        $global = RankingTestSetup::ranking(CompetitionType::Singles, ['name' => 'Global']);
        $primeraRanking = RankingTestSetup::ranking(CompetitionType::Singles, [
            'name' => 'Primera',
            'category_id' => $primera->id,
        ]);
        $segundaRanking = RankingTestSetup::ranking(CompetitionType::Singles, [
            'name' => 'Segunda',
            'category_id' => $segunda->id,
        ]);

        foreach ([$global, $primeraRanking, $segundaRanking] as $ranking) {
            RankingTestSetup::rule($ranking, [
                'source' => CompetitionFinalStandingSource::Final,
                'position' => 1,
                'points' => 100,
            ]);
            RankingTestSetup::rule($ranking, [
                'source' => CompetitionFinalStandingSource::Final,
                'position' => 2,
                'points' => 70,
            ]);
        }

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $this->assertSame(2, RankingTransaction::query()->where('ranking_id', $global->id)->count());
        $this->assertSame(2, RankingTransaction::query()->where('ranking_id', $primeraRanking->id)->count());
        $this->assertSame(0, RankingTransaction::query()->where('ranking_id', $segundaRanking->id)->count());
    }

    public function test_changing_category_moves_transactions_off_stale_ranking(): void
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

        foreach ([$primeraRanking, $segundaRanking] as $ranking) {
            RankingTestSetup::rule($ranking, [
                'source' => CompetitionFinalStandingSource::Final,
                'position' => 1,
                'points' => 100,
                'priority' => 20,
            ]);
            RankingTestSetup::rule($ranking, [
                'source' => CompetitionFinalStandingSource::Final,
                'position' => 2,
                'points' => 70,
                'priority' => 19,
            ]);
        }

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $this->assertSame(2, RankingTransaction::query()->where('ranking_id', $primeraRanking->id)->count());
        $this->assertSame(0, RankingTransaction::query()->where('ranking_id', $segundaRanking->id)->count());

        $competition->update(['category_id' => $segunda->id, 'category' => 'segunda']);
        app(AwardRankingFromFinalStandingsAction::class)($competition->fresh());

        $this->assertSame(0, RankingTransaction::query()->where('ranking_id', $primeraRanking->id)->count());
        $this->assertSame(2, RankingTransaction::query()->where('ranking_id', $segundaRanking->id)->count());
        $this->assertSame(0, $primeraRanking->fresh()->standings()->count());
        $this->assertSame(2, $segundaRanking->fresh()->standings()->count());
    }

    public function test_season_window_excludes_tournament_outside_dates(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, [
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);
        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 1,
            'points' => 100,
        ]);
        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::Final,
            'position' => 2,
            'points' => 70,
        ]);

        $competition = $context->createKnockoutDirectCompetition();
        $competition->tournament->update(['start_date' => '2025-06-01']);
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $this->assertSame(0, RankingTransaction::query()->where('ranking_id', $ranking->id)->count());
    }
}
