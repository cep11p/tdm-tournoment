<?php

namespace App\Http\Resources\GroupPlayer;

use App\Enums\GroupPlayerStatus;
use App\Models\GroupEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupPlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var GroupEntry $groupEntry */
        $groupEntry = $this->resource;
        $player = $groupEntry->competitionEntry?->singlesPlayer();
        $status = $groupEntry->status ?? GroupPlayerStatus::Active;

        return [
            'id' => $groupEntry->id,
            'group_id' => $groupEntry->group_id,
            'player_id' => $player?->id,
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
            'status_reason' => $groupEntry->status_reason?->value,
            'status_notes' => $groupEntry->status_notes,
            'status_changed_at' => $groupEntry->status_changed_at?->toIso8601String(),
            'created_at' => optional($groupEntry->created_at)->toISOString(),
            'updated_at' => optional($groupEntry->updated_at)->toISOString(),
        ];
    }
}
