<?php

namespace App\Support\Ranking;

use App\Models\RankingStanding;
use Illuminate\Support\Collection;

final class RankingStandingsTable
{
    /**
     * @param  Collection<int, RankingStanding>  $standings
     * @return list<array{position: int, standing: RankingStanding}>
     */
    public static function ranked(Collection $standings): array
    {
        $sorted = $standings
            ->sort(function (RankingStanding $left, RankingStanding $right): int {
                $pointsComparison = (int) $right->points_total <=> (int) $left->points_total;

                if ($pointsComparison !== 0) {
                    return $pointsComparison;
                }

                $leftName = PlayerRankingDisplayName::for($left->player);
                $rightName = PlayerRankingDisplayName::for($right->player);
                $nameComparison = strcasecmp($leftName, $rightName);

                if ($nameComparison !== 0) {
                    return $nameComparison;
                }

                return (int) $left->player_id <=> (int) $right->player_id;
            })
            ->values();

        $rows = [];
        $lastPoints = null;
        $lastPosition = 0;

        foreach ($sorted as $index => $standing) {
            $points = (int) $standing->points_total;

            if ($lastPoints === null || $points !== $lastPoints) {
                $lastPosition = $index + 1;
                $lastPoints = $points;
            }

            $rows[] = [
                'position' => $lastPosition,
                'standing' => $standing,
            ];
        }

        return $rows;
    }
}
