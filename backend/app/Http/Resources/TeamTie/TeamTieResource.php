<?php

namespace App\Http\Resources\TeamTie;

use App\Enums\TeamTieStatus;
use App\Http\Resources\Game\CompetitionEntrySideResource;
use App\Models\CompetitionEntry;
use App\Support\TeamTie\TeamTieScoreResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamTieResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof TeamTieStatus
            ? $this->status->value
            : (string) $this->status;

        $score = TeamTieScoreResolver::resolve($this->resource);
        $rubbersWithLineup = TeamTieScoreResolver::rubbersWithLineupCount($this->resource);

        $winnerEntry = $this->winner_entry_id !== null
            ? $this->resolveWinnerEntry()
            : null;

        return [
            'id' => $this->id,
            'competition_id' => $this->competition_id,
            'group_id' => $this->group_id,
            'status' => $status,
            'is_bye' => (bool) $this->is_bye,
            'entry1' => new CompetitionEntrySideResource($this->whenLoaded('entry1')),
            'entry2' => new CompetitionEntrySideResource($this->whenLoaded('entry2')),
            'winner_entry_id' => $this->winner_entry_id,
            'winner' => $winnerEntry !== null
                ? new CompetitionEntrySideResource($winnerEntry)
                : null,
            'format' => [
                'id' => $this->team_tie_format_id,
                'name' => $this->format_name,
                'victories_required' => $this->victories_required,
            ],
            'group_round' => $this->group_round,
            'group_match' => $this->group_match,
            'finished_at' => optional($this->finished_at)->toISOString(),
            'rubbers_total' => $score['rubbers_total'],
            'rubbers_with_lineup' => $rubbersWithLineup,
            'score' => $score,
            'team_tie_games' => $this->when(
                $request->routeIs('team-ties.show'),
                TeamTieGameResource::collection($this->whenLoaded('teamTieGames')),
            ),
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }

    private function resolveWinnerEntry(): ?CompetitionEntry
    {
        if ($this->relationLoaded('winnerEntry') && $this->winnerEntry !== null) {
            return $this->winnerEntry;
        }

        $winnerEntryId = (int) $this->winner_entry_id;

        if ($this->relationLoaded('entry1') && (int) $this->entry1?->id === $winnerEntryId) {
            return $this->entry1;
        }

        if ($this->relationLoaded('entry2') && (int) $this->entry2?->id === $winnerEntryId) {
            return $this->entry2;
        }

        return $this->winnerEntry;
    }
}
