<?php

namespace App\Http\Resources\TeamTie;

use App\Enums\GameStatus;
use App\Enums\TeamTieGameSide;
use App\Enums\TeamTieModality;
use App\Models\TeamTieGame;
use App\Support\Competition\CompetitionEntryDisplayName;
use App\Support\Competition\CompetitionEntryMemberPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamTieGameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var TeamTieGame $teamTieGame */
        $teamTieGame = $this->resource;

        $teamTie = $teamTieGame->teamTie;
        $game = $teamTieGame->game;

        $modality = $teamTieGame->modality instanceof TeamTieModality
            ? $teamTieGame->modality
            : TeamTieModality::from((string) $teamTieGame->modality);

        return [
            'id' => $teamTieGame->id,
            'team_tie_id' => $teamTieGame->team_tie_id,
            'slot_order' => $teamTieGame->slot_order,
            'modality' => $modality->value,
            'modality_label' => $modality->label(),
            'entry1' => $teamTie?->entry1 !== null ? [
                'competition_entry_id' => (int) $teamTie->entry1->id,
                'display_name' => CompetitionEntryDisplayName::for($teamTie->entry1),
            ] : null,
            'entry2' => $teamTie?->entry2 !== null ? [
                'competition_entry_id' => (int) $teamTie->entry2->id,
                'display_name' => CompetitionEntryDisplayName::for($teamTie->entry2),
            ] : null,
            'lineup' => $this->lineupPayload(),
            'lineup_complete' => $teamTieGame->isLineupComplete(),
            'game' => $this->gamePayload($game),
        ];
    }

    /**
     * @return array{entry1: list<array<string, mixed>>, entry2: list<array<string, mixed>>}
     */
    private function lineupPayload(): array
    {
        $teamTieGame = $this->resource;
        $teamTieGame->loadMissing('members.competitionEntryMember.player');

        $entry1 = [];
        $entry2 = [];

        foreach ($teamTieGame->members as $member) {
            $player = $member->competitionEntryMember?->player;
            $payload = CompetitionEntryMemberPayload::forPlayer($player);

            if ($member->side === TeamTieGameSide::Entry1) {
                $entry1[] = [
                    'player_id' => $payload['id'],
                    'first_name' => $payload['first_name'],
                    'last_name' => $payload['last_name'],
                    'nickname' => $payload['nickname'],
                ];
            } else {
                $entry2[] = [
                    'player_id' => $payload['id'],
                    'first_name' => $payload['first_name'],
                    'last_name' => $payload['last_name'],
                    'nickname' => $payload['nickname'],
                ];
            }
        }

        usort($entry1, fn (array $left, array $right): int => ($left['player_id'] ?? 0) <=> ($right['player_id'] ?? 0));
        usort($entry2, fn (array $left, array $right): int => ($left['player_id'] ?? 0) <=> ($right['player_id'] ?? 0));

        return [
            'entry1' => $entry1,
            'entry2' => $entry2,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function gamePayload(?\App\Models\Game $game): ?array
    {
        if ($game === null) {
            return null;
        }

        $status = $game->status instanceof GameStatus
            ? $game->status->value
            : (string) $game->status;

        $setsWon = $game->setsWonCount(
            $game->relationLoaded('sets') ? $game->sets : null
        );

        return [
            'id' => $game->id,
            'status' => $status,
            'winner_entry_id' => $game->winner_entry_id,
            'best_of' => $game->best_of,
            'sets_to_win' => $game->sets_to_win,
            'sets_won' => $setsWon,
            'finished_at' => optional($game->finished_at)->toISOString(),
        ];
    }
}
