<?php

namespace App\Http\Resources\PlayingTable;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayingTableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => $this->name,
            'display_name' => $this->displayName(),
            'active' => $this->active,
            'sort_order' => $this->sort_order,
        ];
    }
}
