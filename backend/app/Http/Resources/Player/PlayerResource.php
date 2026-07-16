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
            'category_id' => $this->category_id,
            'club_id' => $this->club_id,
            'category' => $this->whenLoaded('category', function () {
                if ($this->category === null) {
                    return null;
                }

                return [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                ];
            }),
            'club' => $this->whenLoaded('club', function () {
                if ($this->club === null) {
                    return null;
                }

                return [
                    'id' => $this->club->id,
                    'name' => $this->club->name,
                ];
            }),
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
