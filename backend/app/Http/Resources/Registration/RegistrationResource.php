<?php

namespace App\Http\Resources\Registration;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $player = $this->whenLoaded('player');

        return [
            'id' => $this->id,
            'competition_id' => $this->competition_id,
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
