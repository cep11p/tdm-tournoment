<?php

namespace App\Http\Resources\Bracket;

use App\Data\Bracket\PrintBracketData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintBracketResource extends JsonResource
{
    /**
     * @var PrintBracketData
     */
    public $resource;

    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
