<?php

namespace App\Http\Resources\Ranking;

use App\Enums\CompetitionType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RankingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->competition_type instanceof CompetitionType
            ? $this->competition_type->value
            : (string) $this->competition_type;

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'competition_type' => $type,
            'season' => (string) $this->season,
            'category' => $this->whenLoaded('category', function () {
                if ($this->category === null) {
                    return null;
                }

                return [
                    'id' => (int) $this->category->id,
                    'name' => (string) $this->category->name,
                    'slug' => (string) $this->category->slug,
                ];
            }),
            'active' => (bool) $this->active,
            'starts_at' => optional($this->starts_at)?->toDateString(),
            'ends_at' => optional($this->ends_at)?->toDateString(),
            'rules_locked' => $this->when(
                array_key_exists('transactions_exists', $this->resource->getAttributes()),
                fn () => (bool) $this->resource->transactions_exists,
            ),
            'rules' => $this->whenLoaded('rules', fn () => RankingRuleResource::collection($this->rules)),
        ];
    }
}
