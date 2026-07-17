<?php

namespace App\Support\Audit;

use App\Data\Audit\AuditEntry;
use Spatie\Activitylog\Models\Activity;

final class AuditLogger
{
    public function __construct(
        private readonly AuditContext $auditContext,
    ) {}

    public function log(AuditEntry $entry): Activity
    {
        $context = $this->auditContext->resolve();

        $pending = activity($entry->logName)
            ->performedOn($entry->subject)
            ->withProperties([
                'schema_version' => 1,
                'context' => $entry->context,
                'old' => $entry->old,
                'new' => $entry->new,
                'summary' => $entry->summary,
                'reason' => $entry->reason,
                'actor' => [
                    'keycloak_id' => $context->keycloakId,
                ],
                'request' => [
                    'ip_address' => $context->ipAddress,
                    'user_agent' => $context->userAgent,
                ],
            ]);

        if ($context->user !== null) {
            $pending->causedBy($context->user);
        }

        return $pending->log($entry->action->value);
    }
}
