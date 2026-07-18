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

    public static function oidcBaseUrl(): string
    {
        self::ensureConfigured();

        $baseUrl = trim((string) config('keycloak.oidc_base_url', ''));

        if ($baseUrl === '') {
            $baseUrl = trim((string) config('keycloak.issuer', ''));
        }

        return rtrim($baseUrl, '/');
    }

    public static function resolveInternalJwksUri(string $publicJwksUri): string
    {
        $issuer = self::normalizedIssuer();
        $oidcBase = self::oidcBaseUrl();

        if ($oidcBase === $issuer) {
            return rtrim(trim($publicJwksUri), '/');
        }

        $normalizedPublic = rtrim(trim($publicJwksUri), '/');

        if (! str_starts_with($normalizedPublic, $issuer.'/')) {
            throw new KeycloakConfigurationException(
                'El jwks_uri del documento OIDC no pertenece al issuer configurado.',
            );
        }

        $path = substr($normalizedPublic, strlen($issuer));

        if ($path === false || ! str_starts_with($path, '/protocol/openid-connect/')) {
            throw new KeycloakConfigurationException(
                'El jwks_uri del documento OIDC no es válido para el realm configurado.',
            );
        }

        return $oidcBase.$path;
    }

    public static function apiAudience(): string
    {
        self::ensureConfigured();

        return trim((string) config('keycloak.api_audience'));
    }
}
