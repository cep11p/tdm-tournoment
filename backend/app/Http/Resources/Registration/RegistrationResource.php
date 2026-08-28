<?php

namespace App\Http\Resources\Registration;

use App\Enums\CompetitionType;
use App\Models\CompetitionEntryMember;
use App\Models\Player;
use App\Support\Competition\CompetitionEntryDisplayName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $entry = $this->resource;
        $entry->loadMissing(['members.player', 'competition']);

        $type = $entry->competition?->type instanceof CompetitionType
            ? $entry->competition->type
            : CompetitionType::Singles;

        $members = $entry->members
            ->sortBy('member_order')
            ->values();

        $memberPayload = $members
            ->map(fn (CompetitionEntryMember $member): array => $this->playerPayload($member->player))
            ->values()
            ->all();

        $isSingles = $type === CompetitionType::Singles;

        return [
            'id' => $entry->id,
            'competition_id' => $entry->competition_id,
            'display_name' => CompetitionEntryDisplayName::for($entry),
            'members' => $memberPayload,
            'player' => $isSingles ? ($memberPayload[0] ?? null) : null,
            'created_at' => optional($entry->created_at)->toISOString(),
            'updated_at' => optional($entry->updated_at)->toISOString(),
        ];
    }

    /**
     * @return array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}
     */
    private function playerPayload(?Player $player): array
    {
        return [
            'id' => $player?->id,
            'first_name' => $player?->first_name,
            'last_name' => $player?->last_name,
            'nickname' => $player?->nickname,
        ];
    }
}
