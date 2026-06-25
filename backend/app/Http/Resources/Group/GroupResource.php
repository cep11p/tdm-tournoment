<?php

namespace App\Http\Resources\Group;

use App\Http\Resources\GroupPlayer\GroupPlayerResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'competition_id' => $this->competition_id,
            'name' => $this->name,
            'group_players' => GroupPlayerResource::collection($this->whenLoaded('groupPlayers')),
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
