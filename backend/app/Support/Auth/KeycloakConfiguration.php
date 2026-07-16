<?php

namespace App\Support\Auth;

use App\Support\Auth\Exceptions\KeycloakConfigurationException;

final class KeycloakConfiguration
{
    public static function ensureConfigured(): void
    {
        $issuer = trim((string) config('keycloak.issuer', ''));
        $audience = trim((string) config('keycloak.api_audience', ''));

        if ($issuer === '' || $audience === '') {
            throw new KeycloakConfigurationException(
                'Keycloak no está configurado: KEYCLOAK_ISSUER y KEYCLOAK_API_AUDIENCE son obligatorios.',
            );
        }
    }

    public static function normalizedIssuer(): string
    {
        self::ensureConfigured();

        return rtrim(trim((string) config('keycloak.issuer')), '/');
    }

    public static function apiAudience(): string
    {
        self::ensureConfigured();

        return trim((string) config('keycloak.api_audience'));
    }
}
