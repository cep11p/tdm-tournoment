<?php

namespace App\Http\Resources\GroupPlayer;

use App\Enums\GroupPlayerStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupPlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $player = $this->whenLoaded('player');
        $status = $this->status ?? GroupPlayerStatus::Active;

        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'player_id' => $this->player_id,
            'player_name' => trim(sprintf(
                '%s %s',
                (string) $player?->first_name,
                (string) $player?->last_name
            )),
            'player' => [
                'id' => $player?->id,
                'first_name' => $player?->first_name,
                'last_name' => $player?->last_name,
                'nickname' => $player?->nickname,
            ],
            'status' => $status->value,
            'status_reason' => $this->status_reason?->value,
            'status_notes' => $this->status_notes,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
