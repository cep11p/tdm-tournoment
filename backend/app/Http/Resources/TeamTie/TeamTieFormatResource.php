<?php

namespace App\Http\Resources\TeamTie;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamTieFormatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'victories_required' => $this->victories_required,
            'active' => (bool) $this->active,
            'slots' => TeamTieFormatSlotResource::collection(
                $this->whenLoaded('slots'),
            ),
        ];
    }
}
