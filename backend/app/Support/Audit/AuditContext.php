<?php

namespace App\Support\Audit;

use App\Http\Middleware\AuthenticateKeycloak;
use App\Models\User;
use App\Support\Auth\AuthenticatedContext;
use App\Support\Auth\AuthenticatedIdentity;
use Illuminate\Support\Facades\Auth;

final class AuditContext
{
    public function resolve(): AuditExecutionContext
    {
        /** @var User|null $user */
        $user = Auth::user();

        $keycloakId = $this->resolveKeycloakId($user);
        $ipAddress = null;
        $userAgent = null;

        if (app()->bound('request') && $this->hasAuthenticatedHttpContext()) {
            $request = request();
            $ipAddress = $request->ip();
            $userAgent = $request->userAgent();
        }

        return new AuditExecutionContext(
            user: $user,
            keycloakId: $keycloakId,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }

    private function hasAuthenticatedHttpContext(): bool
    {
        $request = request();

        if ($request->attributes->get(AuthenticatedContext::ATTRIBUTE) instanceof AuthenticatedContext) {
            return true;
        }

        $authorization = trim((string) $request->header('Authorization', ''));

        return $authorization !== '' && str_starts_with(strtolower($authorization), 'bearer ');
    }

    private function resolveKeycloakId(?User $user): ?string
    {
        if (! app()->bound('request')) {
            return $user?->keycloak_id;
        }

        $authenticatedContext = request()->attributes->get(AuthenticatedContext::ATTRIBUTE);

        if ($authenticatedContext instanceof AuthenticatedContext) {
            return $authenticatedContext->identity->subject;
        }

        $identity = request()->attributes->get(AuthenticateKeycloak::IDENTITY_ATTRIBUTE);

        if ($identity instanceof AuthenticatedIdentity) {
            return $identity->subject;
        }

        return $user?->keycloak_id;
    }
}
