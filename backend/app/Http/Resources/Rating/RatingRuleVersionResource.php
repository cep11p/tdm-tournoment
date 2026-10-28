<?php

namespace App\Http\Resources\Rating;

use App\Enums\RatingRuleVersionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RatingRuleVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof RatingRuleVersionStatus
            ? $this->status->value
            : (string) $this->status;

        return [
            'id' => (int) $this->id,
            'rating_system_id' => (int) $this->rating_system_id,
            'version' => (int) $this->version,
            'status' => $status,
            'rules' => is_array($this->rules) ? $this->rules : [],
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
