<?php

namespace App\Http\Resources\Tournament;

use App\Enums\TournamentStatus;
use App\Support\Tournament\TournamentClosureGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TournamentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof TournamentStatus
            ? $this->status->value
            : (string) $this->status;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'location' => $this->location,
            'start_date' => optional($this->start_date)->toDateString(),
            'end_date' => optional($this->end_date)->toDateString(),
            'status' => $status,
            'closed_at' => optional($this->closed_at)->toIso8601String(),
            'results_summary' => $this->when(
                $status === TournamentStatus::Finished->value,
                fn (): ?array => TournamentClosureGuard::buildSummaryForClosedTournament($this->resource),
            ),
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
