<?php

namespace App\Http\Resources\Competition;

use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompetitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->type instanceof CompetitionType
            ? $this->type->value
            : (string) $this->type;

        $format = $this->format instanceof CompetitionFormat
            ? $this->format->value
            : (string) $this->format;

        return [
            'id' => $this->id,
            'tournament_id' => $this->tournament_id,
            'name' => $this->name,
            'category' => $this->category,
            'type' => $type,
            'format' => $format,
            'sets_to_win' => $this->sets_to_win,
            'points_per_set' => $this->points_per_set,
            'group_stage_best_of' => $this->group_stage_best_of,
            'knockout_stage_best_of' => $this->knockout_stage_best_of,
            'semifinal_best_of' => $this->semifinal_best_of,
            'final_best_of' => $this->final_best_of,
            'qualified_per_group' => $this->qualified_per_group,
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
