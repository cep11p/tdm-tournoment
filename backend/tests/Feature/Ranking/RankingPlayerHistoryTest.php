<?php

namespace Tests\Feature\Ranking;

use App\Actions\Ranking\RebuildRankingStandingsAction;
use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\CompetitionEntryMember;
use App\Models\Player;
use App\Models\Ranking;
use App\Models\RankingStanding;
use App\Models\RankingTransaction;
use Database\Seeders\RankingSeeder;
use Tests\Support\RankingTestSetup;
use Tests\TestCase;

class RankingPlayerHistoryTest extends TestCase
{
    public function test_happy_path_returns_envelope_without_auth(): void
    {
        $this->seed(RankingSeeder::class);
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['name' => 'Singles — Finalizada']);
        $competition->tournament->update(['name' => 'Torneo Demo TDM — Finalizado']);

        $players = $context->createPlayers(2);
        $players[0]->update(['first_name' => 'Carlos', 'last_name' => 'Perez']);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $context->finishGame($competition->games()->where('round', 'Final')->firstOrFail(), $players[0])->assertOk();

        $ranking = Ranking::query()->where('name', RankingSeeder::SINGLES_NAME)->firstOrFail();
        $standing = RankingStanding::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $players[0]->id)
            ->firstOrFail();

        $data = $this->withoutHeader('Authorization')
            ->getJson($this->historyUrl($ranking, $players[0]))
            ->assertOk()
            ->json('data');

        $this->assertSame($ranking->id, $data['ranking']['id']);
        $this->assertSame(RankingSeeder::SINGLES_NAME, $data['ranking']['name']);
        $this->assertSame('singles', $data['ranking']['competition_type']);
        $this->assertSame('2026', $data['ranking']['season']);
        $this->assertArrayNotHasKey('rules', $data['ranking']);

        $this->assertSame($players[0]->id, $data['player']['id']);
        $this->assertSame('Carlos Perez', $data['player']['display_name']);

        $this->assertSame((int) $standing->points_total, $data['summary']['points']);
        $this->assertSame((int) $standing->events_count, $data['summary']['events_count']);
        $this->assertSame(100, $data['summary']['points']);
        $this->assertSame(1, $data['summary']['events_count']);

        $this->assertCount(1, $data['transactions']);
        $row = $data['transactions'][0];
        $this->assertSame($competition->id, $row['competition_id']);
        $this->assertSame($competition->tournament_id, $row['tournament_id']);
        $this->assertSame('Singles — Finalizada', $row['competition_name']);
        $this->assertSame('Torneo Demo TDM — Finalizado', $row['tournament_name']);
        $this->assertSame('Campeón', $row['result_label']);
        $this->assertSame(1, $row['position']);
        $this->assertSame(1, $row['position_range_end']);
        $this->assertSame(100, $row['points']);
        $this->assertSame($competition->tournament->fresh()->start_date->toDateString(), $row['date']);
        $this->assertArrayHasKey('id', $row);

        $this->assertArrayNotHasKey('source', $row);
        $this->assertArrayNotHasKey('ranking_rule_id', $row);
        $this->assertArrayNotHasKey('category_id', $row);
        $this->assertArrayNotHasKey('competition_type', $row);
        $this->assertArrayNotHasKey('player_display_name_snapshot', $row);
        $this->assertArrayNotHasKey('priority', $row);
    }

    public function test_missing_ranking_returns_404(): void
    {
        $player = Player::query()->create([
            'first_name' => 'Carlos',
            'last_name' => 'Perez',
        ]);

        $this->getJson('/api/v1/rankings/999999/players/'.$player->id.'/transactions')
            ->assertNotFound();
    }

    public function test_missing_player_returns_404(): void
    {
        $ranking = RankingTestSetup::ranking();

        $this->getJson('/api/v1/rankings/'.$ranking->id.'/players/999999/transactions')
            ->assertNotFound();
    }

    public function test_player_without_standing_returns_empty_200(): void
    {
        $ranking = RankingTestSetup::ranking();
        $player = Player::query()->create([
            'first_name' => 'Ana',
            'last_name' => 'SinPuntos',
        ]);

        $data = $this->getJson($this->historyUrl($ranking, $player))
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $data['summary']['points']);
        $this->assertSame(0, $data['summary']['events_count']);
        $this->assertSame([], $data['transactions']);
        $this->assertSame('Ana SinPuntos', $data['player']['display_name']);
    }

    public function test_transactions_are_ordered_by_tournament_start_date_then_competition_id(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $older = $context->createKnockoutDirectCompetition();
        $sameDateFirst = $context->createKnockoutDirectCompetition();
        $sameDateSecond = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(1);
        $player = $players[0];

        $older->tournament->update(['start_date' => '2026-01-15']);
        $sameDateFirst->tournament->update(['start_date' => '2026-06-01']);
        $sameDateSecond->tournament->update(['start_date' => '2026-06-01']);

        $ranking = RankingTestSetup::ranking();

        $this->createTransaction($ranking, $older, $player, 25, 'Cuartos de final');
        $this->createTransaction($ranking, $sameDateFirst, $player, 70, 'Subcampeón');
        $this->createTransaction($ranking, $sameDateSecond, $player, 100, 'Campeón');

        app(RebuildRankingStandingsAction::class)($ranking);

        $this->assertTrue($sameDateSecond->id > $sameDateFirst->id);

        $data = $this->getJson($this->historyUrl($ranking, $player))
            ->assertOk()
            ->json('data');

        $this->assertSame(
            [$sameDateSecond->id, $sameDateFirst->id, $older->id],
            array_column($data['transactions'], 'competition_id'),
        );
        $this->assertSame(['2026-06-01', '2026-06-01', '2026-01-15'], array_column($data['transactions'], 'date'));
        $this->assertSame(195, $data['summary']['points']);
        $this->assertSame(3, $data['summary']['events_count']);
    }

    public function test_summary_matches_ranking_standing(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $first = $context->createKnockoutDirectCompetition();
        $second = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(1);
        $ranking = RankingTestSetup::ranking();

        $this->createTransaction($ranking, $first, $players[0], 100, 'Campeón');
        $this->createTransaction($ranking, $second, $players[0], 25, 'Cuartos de final');
        app(RebuildRankingStandingsAction::class)($ranking);

        $standing = RankingStanding::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $players[0]->id)
            ->firstOrFail();

        $data = $this->getJson($this->historyUrl($ranking, $players[0]))
            ->assertOk()
            ->json('data');

        $this->assertSame((int) $standing->points_total, $data['summary']['points']);
        $this->assertSame((int) $standing->events_count, $data['summary']['events_count']);
        $this->assertSame(125, $data['summary']['points']);
        $this->assertSame(2, $data['summary']['events_count']);
    }

    public function test_singles_champion_history(): void
    {
        $this->seed(RankingSeeder::class);
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $context->finishGame($competition->games()->where('round', 'Final')->firstOrFail(), $players[0])->assertOk();

        $ranking = Ranking::query()->where('name', RankingSeeder::SINGLES_NAME)->firstOrFail();
        $data = $this->getJson($this->historyUrl($ranking, $players[0]))
            ->assertOk()
            ->json('data');

        $this->assertSame('Campeón', $data['transactions'][0]['result_label']);
        $this->assertSame(100, $data['transactions'][0]['points']);
        $this->assertSame(100, $data['summary']['points']);
        $this->assertSame(1, $data['summary']['events_count']);
    }

    public function test_doubles_awards_each_member_without_pair_subject(): void
    {
        $this->seed(RankingSeeder::class);
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createDoublesKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $players[0]->update(['first_name' => 'Carlos', 'last_name' => 'Perez']);
        $players[1]->update(['first_name' => 'Juan', 'last_name' => 'Gomez']);
        $context->registerPair($competition, $players[0], $players[1]);
        $context->registerPair($competition, $players[2], $players[3]);
        $context->createBracket($competition)->assertCreated();
        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGameByEntryViaApi($final, (int) $final->entry1_id)->assertOk();

        $ranking = Ranking::query()->where('name', RankingSeeder::DOUBLES_NAME)->firstOrFail();
        $winnerPlayerIds = CompetitionEntryMember::query()
            ->where('competition_entry_id', (int) $final->fresh()->winner_entry_id)
            ->orderBy('player_id')
            ->pluck('player_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertCount(2, $winnerPlayerIds);

        foreach ($winnerPlayerIds as $playerId) {
            $player = Player::query()->findOrFail($playerId);
            $data = $this->getJson($this->historyUrl($ranking, $player))
                ->assertOk()
                ->json('data');

            $this->assertSame(100, $data['summary']['points']);
            $this->assertSame(1, $data['summary']['events_count']);
            $this->assertSame(100, $data['transactions'][0]['points']);
            $this->assertSame('Campeón', $data['transactions'][0]['result_label']);
            $this->assertSame($player->first_name.' '.$player->last_name, $data['player']['display_name']);
            $this->assertStringNotContainsString(' / ', $data['player']['display_name']);
            $this->assertArrayNotHasKey('partner', $data);
            $this->assertArrayNotHasKey('partner_display_name', $data['transactions'][0]);
        }
    }

    public function test_zero_point_transaction_is_visible_with_source_fallback_label(): void
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
        $context->finishGame($competition->games()->where('round', 'Final')->firstOrFail(), $players[0])->assertOk();

        $data = $this->getJson($this->historyUrl($ranking, $players[0]))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['transactions']);
        $this->assertSame(0, $data['transactions'][0]['points']);
        $this->assertSame('Final', $data['transactions'][0]['result_label']);
        $this->assertSame(0, $data['summary']['points']);
        $this->assertSame(1, $data['summary']['events_count']);
        $this->assertArrayNotHasKey('source', $data['transactions'][0]);
    }

    public function test_final_correction_replaces_ledger_and_keeps_one_row(): void
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

        $before = $this->getJson($this->historyUrl($ranking, $players[0]))->assertOk()->json('data');
        $this->assertSame(100, $before['transactions'][0]['points']);
        $this->assertCount(1, $before['transactions']);

        $final = $final->fresh();
        $player1Won = (int) $final->winner_entry_id === (int) $final->entry1_id;
        $context->correctResult($final, 'Corrección de la Final', [
            $player1Won
                ? ['player1_score' => 0, 'player2_score' => 11]
                : ['player1_score' => 11, 'player2_score' => 0],
        ])->assertOk();

        $after = $this->getJson($this->historyUrl($ranking, $players[0]))->assertOk()->json('data');
        $this->assertCount(1, $after['transactions']);
        $this->assertSame(70, $after['transactions'][0]['points']);
        $this->assertSame('Subcampeón', $after['transactions'][0]['result_label']);
        $this->assertSame(70, $after['summary']['points']);
        $this->assertSame(1, $after['summary']['events_count']);
        $this->assertSame(
            1,
            RankingTransaction::query()
                ->where('ranking_id', $ranking->id)
                ->where('competition_id', $competition->id)
                ->where('player_id', $players[0]->id)
                ->count(),
        );

        $winnerHistory = $this->getJson($this->historyUrl($ranking, $players[1]))->assertOk()->json('data');
        $this->assertSame(100, $winnerHistory['transactions'][0]['points']);
        $this->assertSame('Campeón', $winnerHistory['transactions'][0]['result_label']);
    }

    private function historyUrl(Ranking $ranking, Player $player): string
    {
        return '/api/v1/rankings/'.$ranking->id.'/players/'.$player->id.'/transactions';
    }

    private function createTransaction(
        Ranking $ranking,
        Competition $competition,
        Player $player,
        int $points,
        string $resultLabel,
    ): RankingTransaction {
        return RankingTransaction::query()->create([
            'ranking_id' => $ranking->id,
            'competition_id' => $competition->id,
            'player_id' => $player->id,
            'ranking_rule_id' => null,
            'competition_type' => CompetitionType::Singles,
            'category_id' => $competition->category_id,
            'position' => 1,
            'position_range_end' => 1,
            'source' => CompetitionFinalStandingSource::Final,
            'points' => $points,
            'player_display_name_snapshot' => trim($player->first_name.' '.$player->last_name),
            'competition_name_snapshot' => (string) $competition->name,
            'ranking_rule_name_snapshot' => $resultLabel,
        ]);
    }
}
