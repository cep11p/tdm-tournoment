<?php

namespace App\Actions\Ranking;

use App\Models\Ranking;
use App\Models\RankingStanding;
use App\Models\RankingTransaction;
use Illuminate\Support\Facades\DB;

final class RebuildRankingStandingsAction
{
    public function __invoke(Ranking $ranking): void
    {
        DB::transaction(function () use ($ranking): void {
            RankingStanding::query()
                ->where('ranking_id', $ranking->id)
                ->delete();

            $aggregates = RankingTransaction::query()
                ->where('ranking_id', $ranking->id)
                ->selectRaw('player_id, SUM(points) as points_total, COUNT(DISTINCT competition_id) as events_count')
                ->groupBy('player_id')
                ->orderBy('player_id')
                ->get();

            foreach ($aggregates as $aggregate) {
                RankingStanding::query()->create([
                    'ranking_id' => $ranking->id,
                    'player_id' => (int) $aggregate->player_id,
                    'points_total' => (int) $aggregate->points_total,
                    'events_count' => (int) $aggregate->events_count,
                ]);
            }
        });
    }
}
