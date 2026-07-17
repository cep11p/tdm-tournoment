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
class AuditLogDetailResource extends JsonResource
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
            'old' => data_get($properties, 'old', []),
            'new' => data_get($properties, 'new', []),
            'summary' => data_get($properties, 'summary', []),
            'reason' => data_get($properties, 'reason'),
            'request' => [
                'ip_address' => data_get($properties, 'request.ip_address'),
                'user_agent' => data_get($properties, 'request.user_agent'),
            ],
            'schema_version' => data_get($properties, 'schema_version'),
        ];
    }
}
