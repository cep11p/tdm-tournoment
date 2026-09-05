<?php

namespace App\Http\Resources\Ranking;

use App\Support\Ranking\RankingTransactionResultLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RankingTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $competition = $this->competition;
        $tournament = $competition?->tournament;

        return [
            'id' => (int) $this->id,
            'competition_id' => (int) $this->competition_id,
            'tournament_id' => $competition?->tournament_id !== null
                ? (int) $competition->tournament_id
                : null,
            'competition_name' => (string) $this->competition_name_snapshot,
            'tournament_name' => $tournament !== null ? (string) $tournament->name : '',
            'result_label' => RankingTransactionResultLabel::for($this->resource),
            'position' => (int) $this->position,
            'position_range_end' => (int) $this->position_range_end,
            'points' => (int) $this->points,
            // Fecha deportiva: Tournament.start_date (no created_at del ledger).
            'date' => optional($tournament?->start_date)?->toDateString(),
        ];
    }
}
