<?php

namespace App\Http\Resources\Group;

use App\Data\Group\PrintCompetitionGroupsData;
use App\Data\Group\PrintGroupSheetData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintCompetitionGroupsResource extends JsonResource
{
    /**
     * @var PrintCompetitionGroupsData
     */
    public $resource;

    public function toArray(Request $request): array
    {
        $payload = $this->resource;

        return [
            'tournament' => $payload->tournament,
            'competition' => $payload->competition,
            'groups_count' => $payload->groupsCount,
            'sheets' => array_map(
                fn (PrintGroupSheetData $sheet): array => $sheet->toArray(),
                $payload->sheets,
            ),
        ];
    }
}
