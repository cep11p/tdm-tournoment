<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionEntryStatus;
use App\Enums\CompetitionFinalStandingSource;
use App\Models\CompetitionFinalStanding;
use App\Models\Ranking;
use App\Models\RankingTransaction;
use App\Support\Competition\CompetitionEntryDisplayName;
use App\Support\Competition\CompetitionStatusResolver;
use Database\Seeders\RankingSeeder;
use Tests\TestCase;

class CompetitionFinalStandingsNotInDrawTest extends TestCase
{
    public function test_knockout_persists_not_in_draw_rows_and_exposes_friendly_source_label(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, array_slice($players, 0, 4));
        $context->createBracket($competition)->assertCreated();

        $activeOut = $context->attachSinglesEntry($competition, $players[4]);
        $withdrawnOut = $context->attachSinglesEntry(
            $competition,
            $players[5],
            CompetitionEntryStatus::Withdrawn,
        );

        $context->completeCompetitionThroughFinal($competition);

        $this->assertSame(
            'completed',
            CompetitionStatusResolver::resolve($competition->fresh())['code'],
        );

        $standings = $competition->fresh()->finalStandings()->orderBy('position')->orderBy('competition_entry_id')->get();
        $this->assertCount(6, $standings);

        $notInDraw = $standings->where('source', CompetitionFinalStandingSource::NotInDraw)->values();
        $this->assertCount(2, $notInDraw);
        $this->assertSame(
            [(int) $activeOut->id, (int) $withdrawnOut->id],
            $notInDraw->pluck('competition_entry_id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
        );

        foreach ($notInDraw as $row) {
            $this->assertSame(5, (int) $row->position);
            $this->assertSame(6, (int) $row->position_range_end);
            $this->assertSame(
                CompetitionEntryDisplayName::for($row->entry()->with('members.player')->firstOrFail()),
                $row->display_name_snapshot,
            );
        }

        $this->assertSame(CompetitionEntryStatus::Active, $activeOut->fresh()->status);
        $this->assertSame(CompetitionEntryStatus::Withdrawn, $withdrawnOut->fresh()->status);

        $payload = $this->getJson($context->apiUrl("competitions/{$competition->id}/final-standings"))
            ->assertOk()
            ->json('data');

        $apiNotInDraw = array_values(array_filter(
            $payload,
            fn (array $row): bool => $row['source'] === CompetitionFinalStandingSource::NotInDraw->value,
        ));
        $this->assertCount(2, $apiNotInDraw);
        $this->assertSame('No participó del cuadro', $apiNotInDraw[0]['source_label']);
        $this->assertSame('No participó del cuadro', $apiNotInDraw[1]['source_label']);
        $this->assertSame(5, $apiNotInDraw[0]['position']);
        $this->assertSame(6, $apiNotInDraw[0]['position_range_end']);
    }

    public function test_not_in_draw_awards_zero_ranking_points_without_changing_participants(): void
    {
        $this->seed(RankingSeeder::class);
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, array_slice($players, 0, 2));
        $context->createBracket($competition)->assertCreated();
        $context->attachSinglesEntry($competition, $players[2]);
        $context->attachSinglesEntry($competition, $players[3], CompetitionEntryStatus::Withdrawn);

        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $ranking = Ranking::query()->where('name', RankingSeeder::SINGLES_NAME)->firstOrFail();
        $transactions = RankingTransaction::query()
            ->where('ranking_id', $ranking->id)
            ->where('competition_id', $competition->id)
            ->get();

        $this->assertCount(4, $transactions);
        $this->assertSame(100, (int) $transactions->firstWhere('player_id', $players[0]->id)?->points);
        $this->assertSame(70, (int) $transactions->firstWhere('player_id', $players[1]->id)?->points);

        foreach ([$players[2]->id, $players[3]->id] as $playerId) {
            $row = $transactions->firstWhere('player_id', $playerId);
            $this->assertNotNull($row);
            $this->assertSame(0, (int) $row->points);
            $this->assertNull($row->ranking_rule_id);
            $this->assertSame(CompetitionFinalStandingSource::NotInDraw, $row->source);
            $this->assertSame(3, (int) $row->position);
            $this->assertSame(4, (int) $row->position_range_end);
        }
    }

    public function test_close_tournament_succeeds_when_entries_never_entered_the_fixture(): void
    {
        $this->seed(RankingSeeder::class);
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, array_slice($players, 0, 2));
        $context->createBracket($competition)->assertCreated();
        $context->attachSinglesEntry($competition, $players[2]);
        $context->completeCompetitionThroughFinal($competition);

        $response = $context->closeTournament($competition->tournament);

        $response
            ->assertOk()
            ->assertJsonPath('data.results_summary.completed_competitions', 1);

        $this->assertSame(3, CompetitionFinalStanding::query()->where('competition_id', $competition->id)->count());
        $this->assertTrue(
            CompetitionFinalStanding::query()
                ->where('competition_id', $competition->id)
                ->where('source', CompetitionFinalStandingSource::NotInDraw)
                ->exists(),
        );
        $this->assertSame(
            3,
            RankingTransaction::query()->where('competition_id', $competition->id)->count(),
        );
    }
}
