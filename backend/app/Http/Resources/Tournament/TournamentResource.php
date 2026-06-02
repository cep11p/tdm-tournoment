<?php

namespace App\Http\Resources\Tournament;

use App\Enums\TournamentStatus;
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
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
