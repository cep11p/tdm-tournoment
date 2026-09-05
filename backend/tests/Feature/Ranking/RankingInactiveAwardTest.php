<?php

namespace Tests\Feature\Ranking;

use App\Actions\Competition\ConsolidateCompetitionOutcomeAction;
use App\Actions\Ranking\AwardRankingFromFinalStandingsAction;
use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionType;
use App\Models\Category;
use App\Models\Ranking;
use App\Models\RankingStanding;
use App\Models\RankingTransaction;
use Illuminate\Support\Carbon;
use Tests\Support\RankingTestSetup;
use Tests\TestCase;

class RankingInactiveAwardTest extends TestCase
{
    public function test_inactive_previously_awarded_ranking_survives_final_correction(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['admin']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, ['name' => 'Singles 2026']);
        $this->withChampionRules($ranking);

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $this->assertSame(100, $this->pointsFor($ranking, $players[0]->id));
        $this->assertSame(70, $this->pointsFor($ranking, $players[1]->id));

        $ranking->update(['active' => false]);

        $final = $final->fresh();
        $player1Won = (int) $final->winner_entry_id === (int) $final->entry1_id;
        $context->correctResult($final, 'Corrección de la Final', [
            $player1Won
                ? ['player1_score' => 0, 'player2_score' => 11]
                : ['player1_score' => 11, 'player2_score' => 0],
        ])->assertOk();

        $this->assertFalse((bool) $ranking->fresh()->active);
        $this->assertSame(
            2,
            RankingTransaction::query()
                ->where('ranking_id', $ranking->id)
                ->where('competition_id', $competition->id)
                ->count(),
        );
        $this->assertSame(70, $this->pointsFor($ranking, $players[0]->id));
        $this->assertSame(100, $this->pointsFor($ranking, $players[1]->id));
        $this->assertSame(70, $this->standingPoints($ranking, $players[0]->id));
        $this->assertSame(100, $this->standingPoints($ranking, $players[1]->id));
        $this->assertSame(1, $this->eventsCount($ranking, $players[0]->id));
        $this->assertSame(1, $this->eventsCount($ranking, $players[1]->id));
    }

    public function test_inactive_never_awarded_ranking_does_not_receive_new_points(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, ['active' => false]);
        $this->withChampionRules($ranking);

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $this->assertSame(0, RankingTransaction::query()->where('ranking_id', $ranking->id)->count());

        app(ConsolidateCompetitionOutcomeAction::class)($competition->fresh());

        $this->assertSame(0, RankingTransaction::query()->where('ranking_id', $ranking->id)->count());
        $this->assertSame(0, RankingStanding::query()->where('ranking_id', $ranking->id)->count());
        $this->assertFalse((bool) $ranking->fresh()->active);
    }

    public function test_inactive_retained_ranking_is_idempotent_without_result_change(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking();
        $this->withChampionRules($ranking);

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $ranking->update(['active' => false]);

        $before = RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->orderBy('player_id')
            ->get();

        app(ConsolidateCompetitionOutcomeAction::class)($competition->fresh());

        $after = RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->orderBy('player_id')
            ->get();

        $this->assertCount($before->count(), $after);
        $this->assertSame(
            $before->pluck('player_id')->all(),
            $after->pluck('player_id')->all(),
        );
        $this->assertSame(
            $before->pluck('points')->map(fn ($points): int => (int) $points)->all(),
            $after->pluck('points')->map(fn ($points): int => (int) $points)->all(),
        );
        $this->assertSame(100, $this->standingPoints($ranking, $players[0]->id));
        $this->assertSame(70, $this->standingPoints($ranking, $players[1]->id));
        $this->assertSame(1, $this->eventsCount($ranking, $players[0]->id));
        $this->assertSame(1, $this->eventsCount($ranking, $players[1]->id));
        $this->assertFalse((bool) $ranking->fresh()->active);
    }

    public function test_zero_point_previous_membership_is_retained_when_inactive(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking();
        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::GroupStage,
            'position' => null,
            'points' => 2,
        ]);

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $this->assertSame(2, RankingTransaction::query()->where('ranking_id', $ranking->id)->count());
        $this->assertTrue(
            RankingTransaction::query()
                ->where('ranking_id', $ranking->id)
                ->get()
                ->every(fn (RankingTransaction $row): bool => (int) $row->points === 0),
        );

        $ranking->update(['active' => false]);

        app(ConsolidateCompetitionOutcomeAction::class)($competition->fresh());

        $transactions = RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->where('competition_id', $competition->id)
            ->get();

        $this->assertCount(2, $transactions);
        $this->assertTrue($transactions->every(fn (RankingTransaction $row): bool => (int) $row->points === 0));
        $this->assertFalse((bool) $ranking->fresh()->active);
    }

    public function test_correction_rebuilds_active_global_and_inactive_category_rankings(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['admin']));

        $primera = Category::query()->where('slug', 'primera')->firstOrFail();
        $global = RankingTestSetup::ranking(CompetitionType::Singles, ['name' => 'Global']);
        $primeraRanking = RankingTestSetup::ranking(CompetitionType::Singles, [
            'name' => 'Primera',
            'category_id' => $primera->id,
        ]);

        $this->withChampionRules($global);
        $this->withChampionRules($primeraRanking);

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $primeraRanking->update(['active' => false]);

        $final = $final->fresh();
        $player1Won = (int) $final->winner_entry_id === (int) $final->entry1_id;
        $context->correctResult($final, 'Corrección de la Final', [
            $player1Won
                ? ['player1_score' => 0, 'player2_score' => 11]
                : ['player1_score' => 11, 'player2_score' => 0],
        ])->assertOk();

        foreach ([$global, $primeraRanking] as $ranking) {
            $this->assertSame(
                2,
                RankingTransaction::query()
                    ->where('ranking_id', $ranking->id)
                    ->where('competition_id', $competition->id)
                    ->count(),
            );
            $this->assertSame(70, $this->pointsFor($ranking, $players[0]->id));
            $this->assertSame(100, $this->pointsFor($ranking, $players[1]->id));
            $this->assertSame(70, $this->standingPoints($ranking, $players[0]->id));
            $this->assertSame(100, $this->standingPoints($ranking, $players[1]->id));
        }

        $this->assertTrue((bool) $global->fresh()->active);
        $this->assertFalse((bool) $primeraRanking->fresh()->active);
    }

    public function test_inactive_previously_awarded_ranking_becomes_stale_outside_date_window(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, [
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);
        $this->withChampionRules($ranking);

        $competition = $context->createKnockoutDirectCompetition();
        $competition->tournament->update(['start_date' => Carbon::parse('2026-06-01')]);
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $this->assertSame(2, RankingTransaction::query()->where('ranking_id', $ranking->id)->count());

        $ranking->update(['active' => false]);
        $competition->tournament->update(['start_date' => Carbon::parse('2025-06-01')]);

        app(AwardRankingFromFinalStandingsAction::class)($competition->fresh('tournament'));

        $this->assertSame(0, RankingTransaction::query()->where('ranking_id', $ranking->id)->count());
        $this->assertSame(0, $ranking->fresh()->standings()->count());
        $this->assertFalse((bool) $ranking->fresh()->active);
    }

    private function withChampionRules(Ranking $ranking): void
    {
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
    }

    private function pointsFor(Ranking $ranking, int $playerId): int
    {
        return (int) RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $playerId)
            ->value('points');
    }

    private function standingPoints(Ranking $ranking, int $playerId): int
    {
        return (int) RankingStanding::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $playerId)
            ->value('points_total');
    }

    private function eventsCount(Ranking $ranking, int $playerId): int
    {
        return (int) RankingStanding::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $playerId)
            ->value('events_count');
    }
}
