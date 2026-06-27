<?php

namespace App\Http\Resources\Player;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'nickname' => $this->nickname,
            'full_name' => trim("{$this->first_name} {$this->last_name}"),
            'active' => $this->active,
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];

        if ($this->registrations_count !== null) {
            $data['registrations_count'] = $this->registrations_count;
        }

        if ($this->group_players_count !== null) {
            $data['group_players_count'] = $this->group_players_count;
        }

        if ($this->games_as_player1_count !== null && $this->games_as_player2_count !== null) {
            $data['games_count'] = $this->games_as_player1_count + $this->games_as_player2_count;
        }

        return $data;
    }
}
