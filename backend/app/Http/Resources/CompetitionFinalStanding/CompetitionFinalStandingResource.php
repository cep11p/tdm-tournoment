<?php

namespace App\Http\Resources\CompetitionFinalStanding;

use App\Enums\CompetitionFinalStandingSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompetitionFinalStandingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $source = $this->source instanceof CompetitionFinalStandingSource
            ? $this->source
            : CompetitionFinalStandingSource::tryFrom((string) $this->source);

        return [
            'competition_entry_id' => (int) $this->competition_entry_id,
            'display_name' => (string) $this->display_name_snapshot,
            'position' => (int) $this->position,
            'position_range_end' => (int) $this->position_range_end,
            'source' => $source?->value ?? (string) $this->source,
            'source_label' => $source?->label() ?? (string) $this->source,
        ];
    }
}
