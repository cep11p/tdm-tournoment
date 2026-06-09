<?php

namespace App\Http\Resources\Game;

use App\Enums\GameStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameSetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'game_id' => $this->game_id,
            'set_number' => $this->set_number,
            'player1_score' => $this->player1_score,
            'player2_score' => $this->player2_score,
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
