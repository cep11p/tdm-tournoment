<?php

namespace App\Http\Middleware;

use App\Actions\Auth\SyncKeycloakUserAction;
use App\Support\Auth\Exceptions\KeycloakConfigurationException;
use App\Support\Auth\Exceptions\TokenAuthenticationException;
use App\Support\Auth\KeycloakConfiguration;
use App\Support\Auth\KeycloakTokenValidator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateKeycloak
{
    public const IDENTITY_ATTRIBUTE = 'keycloak_identity';

    public function __construct(
        private readonly KeycloakTokenValidator $tokenValidator,
        private readonly SyncKeycloakUserAction $syncKeycloakUser,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            KeycloakConfiguration::ensureConfigured();
        } catch (KeycloakConfigurationException $exception) {
            Log::error('Keycloak authentication misconfigured.', [
                'reason' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Servicio de autenticación no configurado.',
                'code' => 'auth_misconfigured',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $token = $this->extractBearerToken($request);

        if ($token === null) {
            return $this->unauthenticatedResponse();
        }

        try {
            $identity = $this->tokenValidator->validate($token);
        } catch (TokenAuthenticationException $exception) {
            return $this->unauthenticatedResponse();
        }

        $user = ($this->syncKeycloakUser)($identity);

        Auth::setUser($user);
        $request->setUserResolver(fn (): \App\Models\User => $user);
        $request->attributes->set(self::IDENTITY_ATTRIBUTE, $identity);

        return $next($request);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = trim((string) $request->header('Authorization', ''));

        if ($header === '' || ! str_starts_with(strtolower($header), 'bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }

    private function unauthenticatedResponse(): Response
    {
        return response()->json([
            'message' => 'No autenticado.',
            'code' => 'unauthenticated',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
