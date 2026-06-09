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
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
