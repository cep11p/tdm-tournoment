<?php

namespace App\Support\Audit;

use App\Models\User;

final readonly class AuditExecutionContext
{
    public function __construct(
        public ?User $user,
        public ?string $keycloakId,
        public ?string $ipAddress,
        public ?string $userAgent,
    ) {}
}
