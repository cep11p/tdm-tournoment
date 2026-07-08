<?php

namespace App\Http\Resources\Game;

use App\Enums\GameStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof GameStatus
            ? $this->status->value
            : (string) $this->status;

        $player1 = $this->whenLoaded('player1');
        $player2 = $this->whenLoaded('player2');
        $setsWon = $this->setsWonCount(
            $this->relationLoaded('sets') ? $this->sets : null
        );

        return [
            'id' => $this->id,
            'competition_id' => $this->competition_id,
            'group_id' => $this->group_id,
            'bracket_id' => $this->bracket_id,
            'bracket_round' => $this->bracket_round,
            'bracket_match' => $this->bracket_match,
            'group_round' => $this->group_round,
            'group_match' => $this->group_match,
            'player1' => [
                'id' => $player1?->id,
                'first_name' => $player1?->first_name,
                'last_name' => $player1?->last_name,
                'nickname' => $player1?->nickname,
            ],
            'player2' => [
                'id' => $player2?->id,
                'first_name' => $player2?->first_name,
                'last_name' => $player2?->last_name,
                'nickname' => $player2?->nickname,
            ],
            'winner_id' => $this->winner_id,
            'status' => $status,
            'is_bye' => (bool) $this->is_bye,
            'best_of' => $this->best_of,
            'sets_to_win' => $this->sets_to_win,
            'finished_at' => optional($this->finished_at)->toISOString(),
            'round' => $this->round,
            'table_number' => $this->table_number,
            'sets_won' => $setsWon,
            'sets' => GameSetResource::collection(
                $this->whenLoaded('sets')
            ),
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
