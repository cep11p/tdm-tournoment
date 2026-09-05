<?php

namespace App\Http\Resources\Ranking;

use App\Enums\CompetitionType;
use App\Support\Ranking\PlayerRankingDisplayName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RankingPlayerHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ranking = $this->resource['ranking'];
        $player = $this->resource['player'];
        $standing = $this->resource['standing'];
        $transactions = $this->resource['transactions'];

        $type = $ranking->competition_type instanceof CompetitionType
            ? $ranking->competition_type->value
            : (string) $ranking->competition_type;

        return [
            'ranking' => [
                'id' => (int) $ranking->id,
                'name' => (string) $ranking->name,
                'competition_type' => $type,
                'season' => (string) $ranking->season,
            ],
            'player' => [
                'id' => (int) $player->id,
                'display_name' => PlayerRankingDisplayName::for($player),
            ],
            'summary' => [
                'points' => (int) ($standing?->points_total ?? 0),
                'events_count' => (int) ($standing?->events_count ?? 0),
            ],
            'transactions' => RankingTransactionResource::collection($transactions),
        ];
    }
}
