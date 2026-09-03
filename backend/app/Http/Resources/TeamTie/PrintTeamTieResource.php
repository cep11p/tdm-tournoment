<?php

namespace App\Http\Resources\TeamTie;

use App\Data\TeamTie\PrintTeamTieData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintTeamTieResource extends JsonResource
{
    /**
     * @var PrintTeamTieData
     */
    public $resource;

    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
