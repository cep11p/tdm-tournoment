<?php

namespace Tests\Feature\Ranking;

use App\Enums\CompetitionType;
use App\Models\Ranking;
use App\Models\RankingStanding;
use App\Models\RankingTransaction;
use Database\Seeders\RankingSeeder;
use Tests\Support\RankingTestSetup;
use Tests\TestCase;

class RankingApiTest extends TestCase
{
    public function test_index_and_show_are_public_and_exclude_team(): void
    {
        $this->seed(RankingSeeder::class);

        $index = $this->getJson('/api/v1/rankings')->assertOk()->json('data');
        $names = array_column($index, 'name');

        $this->assertContains(RankingSeeder::SINGLES_NAME, $names);
        $this->assertContains(RankingSeeder::DOUBLES_NAME, $names);
        $this->assertNotContains('team', array_column($index, 'competition_type'));

        $singles = collect($index)->firstWhere('name', RankingSeeder::SINGLES_NAME);
        $this->assertSame('singles', $singles['competition_type']);
        $this->assertSame('2026', $singles['season']);
        $this->assertNull($singles['category']);
        $this->assertTrue($singles['active']);

        $detail = $this->getJson('/api/v1/rankings/'.$singles['id'])->assertOk()->json('data');
        $this->assertSame(RankingSeeder::SINGLES_NAME, $detail['name']);
        $this->assertNotEmpty($detail['rules']);
        $this->assertArrayNotHasKey('transactions', $detail);
    }

    public function test_standings_order_and_shared_positions(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $ranking = RankingTestSetup::ranking(CompetitionType::Singles, ['name' => 'API Ranking']);
        $players = $context->createPlayers(3);

        RankingStanding::query()->create([
            'ranking_id' => $ranking->id,
            'player_id' => $players[0]->id,
            'points_total' => 200,
            'events_count' => 3,
        ]);
        RankingStanding::query()->create([
            'ranking_id' => $ranking->id,
            'player_id' => $players[1]->id,
            'points_total' => 200,
            'events_count' => 2,
        ]);
        RankingStanding::query()->create([
            'ranking_id' => $ranking->id,
            'player_id' => $players[2]->id,
            'points_total' => 180,
            'events_count' => 3,
        ]);

        $data = $this->getJson('/api/v1/rankings/'.$ranking->id.'/standings')
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $data);
        $this->assertSame([1, 1, 3], array_column($data, 'position'));
        $this->assertSame(200, $data[0]['points']);
        $this->assertSame(200, $data[1]['points']);
        $this->assertSame(180, $data[2]['points']);
        $this->assertSame($players[2]->id, $data[2]['player_id']);
        $this->assertSame(3, $data[2]['events_count']);
        $this->assertArrayHasKey('display_name', $data[0]);
    }

    public function test_singles_and_doubles_standings_are_separate(): void
    {
        $this->seed(RankingSeeder::class);
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $singles = $context->createKnockoutDirectCompetition();
        $singlesPlayers = $context->createPlayers(2);
        $context->registerPlayers($singles, $singlesPlayers);
        $context->createBracket($singles)->assertCreated();
        $context->finishGame($singles->games()->where('round', 'Final')->firstOrFail(), $singlesPlayers[0])->assertOk();

        $doubles = $context->createDoublesKnockoutDirectCompetition();
        $doublesPlayers = $context->createPlayers(4);
        $context->registerPair($doubles, $doublesPlayers[0], $doublesPlayers[1]);
        $context->registerPair($doubles, $doublesPlayers[2], $doublesPlayers[3]);
        $context->createBracket($doubles)->assertCreated();
        $final = $doubles->games()->where('round', 'Final')->firstOrFail();
        $context->finishGameByEntryViaApi($final, (int) $final->entry1_id)->assertOk();

        $singlesRanking = Ranking::query()->where('name', RankingSeeder::SINGLES_NAME)->firstOrFail();
        $doublesRanking = Ranking::query()->where('name', RankingSeeder::DOUBLES_NAME)->firstOrFail();

        $singlesStandings = $this->getJson('/api/v1/rankings/'.$singlesRanking->id.'/standings')->assertOk()->json('data');
        $doublesStandings = $this->getJson('/api/v1/rankings/'.$doublesRanking->id.'/standings')->assertOk()->json('data');

        $this->assertCount(2, $singlesStandings);
        $this->assertCount(4, $doublesStandings);
        $this->assertSame(0, RankingTransaction::query()->where('competition_type', 'team')->count());
    }
}
