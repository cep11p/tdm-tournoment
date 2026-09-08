<?php

namespace App\Http\Resources\Bracket;

use App\Http\Resources\Game\GameResource;
use App\Http\Resources\TeamTie\TeamTieResource;
use App\Models\BracketEntryOrigin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BracketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->bindEntryOriginMap($request);

        return [
            'id' => $this->id,
            'competition_id' => $this->competition_id,
            'name' => $this->name,
            'qualifiers_per_group' => $this->qualifiers_per_group,
            'bracket_size' => $this->bracket_size,
            'byes_count' => $this->byes_count,
            'games' => $this->whenLoaded('games', fn () => GameResource::collection($this->games), []),
            'team_ties' => $this->whenLoaded('teamTies', fn () => TeamTieResource::collection($this->teamTies), []),
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }

    private function bindEntryOriginMap(Request $request): void
    {
        $this->resource->loadMissing('entryOrigins');

        $request->attributes->set(
            BracketEntryOrigin::REQUEST_MAP_ATTRIBUTE,
            $this->entryOrigins->keyBy(
                fn (BracketEntryOrigin $origin): int => (int) $origin->competition_entry_id,
            ),
        );
    }
}
