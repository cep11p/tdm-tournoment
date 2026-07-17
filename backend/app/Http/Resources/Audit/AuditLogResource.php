<?php

namespace App\Http\Resources\Audit;

use App\Enums\AuditAction;
use App\Support\Audit\AuditLogActorPresenter;
use App\Support\Audit\AuditLogContextPresenter;
use App\Support\Audit\AuditLogSubjectPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/** @mixin Activity */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $properties = $this->properties?->toArray() ?? [];

        return [
            'id' => $this->id,
            'action' => $this->description,
            'action_label' => AuditAction::labelFor($this->description),
            'category_label' => AuditAction::categoryLabelFor($this->description, $this->log_name),
            'log_name' => $this->log_name,
            'occurred_at' => optional($this->created_at)->toIso8601String(),
            'actor' => AuditLogActorPresenter::present($this->resource),
            'subject' => AuditLogSubjectPresenter::present($this->resource),
            'context' => AuditLogContextPresenter::present($this->resource),
            'summary' => data_get($properties, 'summary', []),
        ];
    }
}
