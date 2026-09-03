<?php

namespace Tests\Feature\Ranking;

use App\Actions\Competition\ConsolidateCompetitionOutcomeAction;
use App\Actions\Ranking\AwardRankingFromFinalStandingsAction;
use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionType;
use App\Models\CompetitionEntryMember;
use App\Models\Ranking;
use App\Models\RankingStanding;
use App\Models\RankingTransaction;
use Database\Seeders\RankingSeeder;
use Illuminate\Validation\ValidationException;
use Tests\Support\RankingTestSetup;
use Tests\TestCase;

class AwardRankingFromFinalStandingsTest extends TestCase
{
    public function test_completed_singles_creates_transactions_and_standings(): void
    {
        $this->seed(RankingSeeder::class);
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $ranking = Ranking::query()->where('name', RankingSeeder::SINGLES_NAME)->firstOrFail();

        $this->assertSame(2, RankingTransaction::query()->where('competition_id', $competition->id)->count());
        $this->assertSame(
            2,
            RankingTransaction::query()->where('ranking_id', $ranking->id)->where('competition_id', $competition->id)->count(),
        );

        $championTx = RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $players[0]->id)
            ->firstOrFail();
        $runnerUpTx = RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $players[1]->id)
            ->firstOrFail();

        $this->assertSame(100, (int) $championTx->points);
        $this->assertSame(70, (int) $runnerUpTx->points);
        $this->assertSame(CompetitionFinalStandingSource::Final, $championTx->source);
        $this->assertSame($players[0]->first_name.' '.$players[0]->last_name, $championTx->player_display_name_snapshot);
        $this->assertSame($competition->name, $championTx->competition_name_snapshot);
        $this->assertNotNull($championTx->ranking_rule_id);
        $this->assertSame('Campeón', $championTx->ranking_rule_name_snapshot);
        $this->assertSame((int) $competition->category_id, (int) $championTx->category_id);

        $this->assertSame(100, (int) RankingStanding::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $players[0]->id)
            ->value('points_total'));
    }

    public function test_doubles_awards_full_points_to_both_members(): void
    {
        $this->seed(RankingSeeder::class);
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createDoublesKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPair($competition, $players[0], $players[1]);
        $context->registerPair($competition, $players[2], $players[3]);
        $context->createBracket($competition)->assertCreated();

        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGameByEntryViaApi($final, (int) $final->entry1_id)->assertOk();

        $ranking = Ranking::query()->where('name', RankingSeeder::DOUBLES_NAME)->firstOrFail();
        $transactions = RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->where('competition_id', $competition->id)
            ->orderBy('player_id')
            ->get();

        $this->assertCount(2, $competition->fresh()->finalStandings);
        $this->assertCount(4, $transactions);

        $winnerEntryId = (int) $final->fresh()->winner_entry_id;
        $winnerPlayerIds = CompetitionEntryMember::query()
            ->where('competition_entry_id', $winnerEntryId)
            ->orderBy('player_id')
            ->pluck('player_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $winnerPoints = RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->whereIn('player_id', $winnerPlayerIds)
            ->pluck('points')
            ->all();

        $this->assertSame([100, 100], array_map('intval', $winnerPoints));
        $this->assertSame(0, RankingTransaction::query()->where('competition_type', CompetitionType::Singles)->count());
    }

    public function test_consolidation_is_idempotent(): void
    {
        $this->seed(RankingSeeder::class);
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $before = RankingTransaction::query()->where('competition_id', $competition->id)->get();

        app(ConsolidateCompetitionOutcomeAction::class)($competition->fresh());

        $after = RankingTransaction::query()->where('competition_id', $competition->id)->get();

        $this->assertCount($before->count(), $after);
        $this->assertSame(
            $before->sortBy('player_id')->pluck('points')->all(),
            $after->sortBy('player_id')->pluck('points')->all(),
        );
    }

    public function test_final_correction_replaces_ledger(): void
    {
        $this->seed(RankingSeeder::class);
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['admin']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $ranking = Ranking::query()->where('name', RankingSeeder::SINGLES_NAME)->firstOrFail();

        $this->assertSame(100, (int) RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $players[0]->id)
            ->value('points'));
        $this->assertSame(70, (int) RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $players[1]->id)
            ->value('points'));

        $final = $final->fresh();
        $player1Won = (int) $final->winner_entry_id === (int) $final->entry1_id;
        $context->correctResult($final, 'Corrección de la Final', [
            $player1Won
                ? ['player1_score' => 0, 'player2_score' => 11]
                : ['player1_score' => 11, 'player2_score' => 0],
        ])->assertOk();

        $this->assertSame(2, RankingTransaction::query()->where('ranking_id', $ranking->id)->where('competition_id', $competition->id)->count());
        $this->assertSame(70, (int) RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $players[0]->id)
            ->value('points'));
        $this->assertSame(100, (int) RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $players[1]->id)
            ->value('points'));
        $this->assertSame(70, (int) RankingStanding::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $players[0]->id)
            ->value('points_total'));
        $this->assertSame(100, (int) RankingStanding::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $players[1]->id)
            ->value('points_total'));
    }

    public function test_unmatched_source_creates_zero_point_transaction(): void
    {
        $ranking = RankingTestSetup::ranking();
        RankingTestSetup::rule($ranking, [
            'source' => CompetitionFinalStandingSource::GroupStage,
            'position' => null,
            'points' => 2,
        ]);

        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $transactions = RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->where('competition_id', $competition->id)
            ->get();

        $this->assertCount(2, $transactions);
        $this->assertTrue($transactions->every(fn (RankingTransaction $row): bool => (int) $row->points === 0));
        $this->assertTrue($transactions->every(fn (RankingTransaction $row): bool => $row->ranking_rule_id === null));
        $this->assertTrue($transactions->every(fn (RankingTransaction $row): bool => $row->ranking_rule_name_snapshot === null));
    }

    public function test_award_without_standings_fails_for_singles(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();

        $this->expectException(ValidationException::class);

        app(AwardRankingFromFinalStandingsAction::class)($competition);
    }
}
