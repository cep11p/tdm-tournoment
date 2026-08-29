<?php

namespace App\Http\Resources\Game;

use App\Enums\BracketGamePurpose;
use App\Enums\CompetitionType;
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

        $this->loadMissing(['competition', 'entry1.members.player', 'entry2.members.player']);

        $isSingles = $this->isSinglesCompetition();
        $setsWon = $this->setsWonCount(
            $this->relationLoaded('sets') ? $this->sets : null
        );

        $player1 = $isSingles ? $this->singlesPlayer1() : null;
        $player2 = $isSingles ? $this->singlesPlayer2() : null;
        $winnerId = $isSingles ? $this->singlesWinnerId() : null;

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
            'side1' => new CompetitionEntrySideResource($this->entry1),
            'side2' => new CompetitionEntrySideResource($this->entry2),
            'winner_entry_id' => $this->winner_entry_id,
            'player1' => $isSingles ? $this->presentPlayer($player1) : null,
            'player2' => $isSingles ? $this->presentPlayer($player2) : null,
            'winner_id' => $winnerId,
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

    private function isSinglesCompetition(): bool
    {
        $type = $this->competition?->type;

        if ($type instanceof CompetitionType) {
            return $type === CompetitionType::Singles;
        }

        return (string) $type === CompetitionType::Singles->value || $type === null;
    }

    /**
     * @return array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}|null
     */
    private function presentPlayer(?Player $player): ?array
    {
        if ($player === null) {
            return null;
        }

        return [
            'id' => $player->id,
            'first_name' => $player->first_name,
            'last_name' => $player->last_name,
            'nickname' => $player->nickname,
        ];
    }
}
