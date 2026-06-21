<?php

namespace App\Http\Resources\Bracket;

use App\Http\Resources\Game\GameResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BracketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'competition_id' => $this->competition_id,
            'name' => $this->name,
            'qualifiers_per_group' => $this->qualifiers_per_group,
            'bracket_size' => $this->bracket_size,
            'byes_count' => $this->byes_count,
            'games' => GameResource::collection($this->whenLoaded('games')),
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
