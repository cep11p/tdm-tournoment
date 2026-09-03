<?php

namespace App\Http\Resources\Ranking;

use App\Enums\CompetitionFinalStandingSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RankingRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $source = $this->source instanceof CompetitionFinalStandingSource
            ? $this->source->value
            : (string) $this->source;

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'source' => $source,
            'position' => $this->position !== null ? (int) $this->position : null,
            'points' => (int) $this->points,
            'priority' => (int) $this->priority,
            'active' => (bool) $this->active,
        ];
    }
}
