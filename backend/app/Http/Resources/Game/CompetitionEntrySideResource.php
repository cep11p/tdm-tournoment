<?php

namespace App\Http\Resources\Game;

use App\Models\CompetitionEntry;
use App\Support\Competition\CompetitionEntrySummaryPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompetitionEntrySideResource extends JsonResource
{
    public function toArray(Request $request): ?array
    {
        if ($this->resource === null) {
            return null;
        }

        /** @var CompetitionEntry $entry */
        $entry = $this->resource;

        return CompetitionEntrySummaryPayload::forEntrySide($entry);
    }
}
