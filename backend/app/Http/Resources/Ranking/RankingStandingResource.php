<?php

namespace App\Http\Resources\Ranking;

use App\Support\Ranking\PlayerRankingDisplayName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RankingStandingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $standing = $this->resource['standing'];
        $player = $standing->player;

        return [
            'position' => (int) $this->resource['position'],
            'player_id' => (int) $standing->player_id,
            'display_name' => $player !== null ? PlayerRankingDisplayName::for($player) : '',
            'points' => (int) $standing->points_total,
            'events_count' => (int) $standing->events_count,
        ];
    }
}
