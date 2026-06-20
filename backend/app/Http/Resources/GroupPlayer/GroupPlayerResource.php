<?php

namespace App\Http\Resources\GroupPlayer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupPlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $player = $this->whenLoaded('player');

        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'player' => [
                'id' => $player?->id,
                'first_name' => $player?->first_name,
                'last_name' => $player?->last_name,
                'nickname' => $player?->nickname,
            ],
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
