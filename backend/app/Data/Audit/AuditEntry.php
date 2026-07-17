<?php

namespace App\Data\Audit;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;

final readonly class AuditEntry
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public AuditAction $action,
        public string $logName,
        public Model $subject,
        public array $context = [],
        public array $old = [],
        public array $new = [],
        public array $summary = [],
        public ?string $reason = null,
    ) {}
}
