<?php

namespace Tests\Unit\Ranking;

use App\Actions\Ranking\RebuildRankingStandingsAction;
use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\Player;
use App\Models\RankingStanding;
use App\Models\RankingTransaction;
use Tests\Support\RankingTestSetup;
use Tests\TestCase;

class RebuildRankingStandingsActionTest extends TestCase
{
    public function test_rebuilds_totals_and_distinct_events(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $first = $context->createKnockoutDirectCompetition();
        $second = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($first, $players);
        $context->registerPlayers($second, $players);

        $ranking = RankingTestSetup::ranking();

        RankingTransaction::query()->create($this->transaction($ranking->id, $first->id, $players[0]->id, 100));
        RankingTransaction::query()->create($this->transaction($ranking->id, $second->id, $players[0]->id, 25));
        RankingTransaction::query()->create($this->transaction($ranking->id, $first->id, $players[1]->id, 70));
        RankingTransaction::query()->create($this->transaction($ranking->id, $second->id, $players[1]->id, 55));

        app(RebuildRankingStandingsAction::class)($ranking);

        $carlos = RankingStanding::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $players[0]->id)
            ->firstOrFail();
        $juan = RankingStanding::query()
            ->where('ranking_id', $ranking->id)
            ->where('player_id', $players[1]->id)
            ->firstOrFail();

        $this->assertSame(125, (int) $carlos->points_total);
        $this->assertSame(2, (int) $carlos->events_count);
        $this->assertSame(125, (int) $juan->points_total);
        $this->assertSame(2, (int) $juan->events_count);
    }

    public function test_rebuild_is_idempotent(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(1);
        $context->registerPlayers($competition, $players);
        $ranking = RankingTestSetup::ranking();

        RankingTransaction::query()->create($this->transaction($ranking->id, $competition->id, $players[0]->id, 40));

        $action = app(RebuildRankingStandingsAction::class);
        $action($ranking);
        $action($ranking);

        $this->assertSame(1, RankingStanding::query()->where('ranking_id', $ranking->id)->count());
        $this->assertSame(40, (int) RankingStanding::query()->where('ranking_id', $ranking->id)->value('points_total'));
    }

    /**
     * @return array<string, mixed>
     */
    private function transaction(int $rankingId, int $competitionId, int $playerId, int $points): array
    {
        $competition = Competition::query()->findOrFail($competitionId);
        $player = Player::query()->findOrFail($playerId);

        return [
            'ranking_id' => $rankingId,
            'competition_id' => $competitionId,
            'player_id' => $playerId,
            'ranking_rule_id' => null,
            'competition_type' => CompetitionType::Singles,
            'category_id' => $competition->category_id,
            'position' => 1,
            'position_range_end' => 1,
            'source' => CompetitionFinalStandingSource::Final,
            'points' => $points,
            'player_display_name_snapshot' => trim($player->first_name.' '.$player->last_name),
            'competition_name_snapshot' => $competition->name,
            'ranking_rule_name_snapshot' => null,
        ];
    }
}
