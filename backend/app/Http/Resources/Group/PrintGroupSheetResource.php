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
        return $this->resource->toArray();
    }
}
