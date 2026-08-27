<?php

namespace App\Http\Resources\Game;

use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof GameStatus
            ? $this->status->value
            : (string) $this->status;

        $bracketPurpose = $this->bracket_purpose instanceof BracketGamePurpose
            ? $this->bracket_purpose
            : BracketGamePurpose::from((string) ($this->bracket_purpose ?? BracketGamePurpose::Main->value));

        $player1 = $this->singlesPlayer1();
        $player2 = $this->singlesPlayer2();
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
            'bracket_purpose' => $bracketPurpose->value,
            'bracket_purpose_label' => $bracketPurpose->label(),
            'group_round' => $this->group_round,
            'group_match' => $this->group_match,
            'player1' => $this->presentPlayer($player1),
            'player2' => $this->presentPlayer($player2),
            'winner_id' => $this->singlesWinnerId(),
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

    /**
     * @return array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}
     */
    private function presentPlayer(?Player $player): array
    {
        return [
            'id' => $player?->id,
            'first_name' => $player?->first_name,
            'last_name' => $player?->last_name,
            'nickname' => $player?->nickname,
        ];
    }
}
