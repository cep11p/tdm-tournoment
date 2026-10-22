<?php

namespace App\Http\Resources\Rating;

use App\Enums\RatingMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RatingSystemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $method = $this->method instanceof RatingMethod
            ? $this->method->value
            : (string) $this->method;

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'description' => $this->description !== null ? (string) $this->description : null,
            'initial_rating' => (int) $this->initial_rating,
            'method' => $method,
            'active' => (bool) $this->active,
        ];
    }
}
