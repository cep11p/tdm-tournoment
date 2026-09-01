<?php

namespace App\Http\Resources\Group;

use App\Data\Group\PrintGroupSheetData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintGroupSheetResource extends JsonResource
{
    /**
     * @var PrintGroupSheetData
     */
    public $resource;

    public function toArray(Request $request): array
    {
        $sheet = $this->resource;

        return [
            'tournament' => $sheet->tournament,
            'competition' => $sheet->competition,
            'group' => $sheet->group,
            'best_of' => $sheet->bestOf,
            'sets_to_win' => $sheet->setsToWin,
            'points_per_set' => $sheet->pointsPerSet,
            'qualified_per_group' => $sheet->qualifiedPerGroup,
            'participants' => $sheet->participants,
            'matches' => $sheet->matches,
        ];
    }
}
