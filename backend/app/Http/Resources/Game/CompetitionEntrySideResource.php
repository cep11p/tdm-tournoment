<?php

namespace App\Http\Resources\Game;

use App\Models\BracketEntryOrigin;
use App\Models\CompetitionEntry;
use App\Support\Competition\CompetitionEntrySummaryPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class CompetitionEntrySideResource extends JsonResource
{
    public function toArray(Request $request): ?array
    {
        if ($this->resource === null) {
            return null;
        }

        /** @var CompetitionEntry $entry */
        $entry = $this->resource;

        $payload = CompetitionEntrySummaryPayload::forEntrySide($entry);

        if (! $request->attributes->has(BracketEntryOrigin::REQUEST_MAP_ATTRIBUTE)) {
            return $payload;
        }

        $origins = $request->attributes->get(BracketEntryOrigin::REQUEST_MAP_ATTRIBUTE);

        if (! $origins instanceof Collection) {
            return $payload;
        }

        $origin = $origins->get((int) $entry->id)
            ?? $origins->get((string) $entry->id);

        $payload['group_origin'] = $origin instanceof BracketEntryOrigin
            ? $origin->toSidePayload()
            : null;

        return $payload;
    }
}
