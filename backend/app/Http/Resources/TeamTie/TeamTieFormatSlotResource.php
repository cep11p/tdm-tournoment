<?php

namespace App\Http\Resources\TeamTie;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamTieFormatSlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $modality = $this->modality;

        return [
            'slot_order' => $this->slot_order,
            'modality' => $modality instanceof \App\Enums\TeamTieModality
                ? $modality->value
                : (string) $modality,
        ];
    }
}
