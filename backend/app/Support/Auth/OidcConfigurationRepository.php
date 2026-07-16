<?php

namespace App\Support\Auth;

use App\Support\Auth\Exceptions\KeycloakConfigurationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class OidcConfigurationRepository
{
    private const CACHE_PREFIX = 'keycloak:oidc:';

    /**
     * @return array{issuer: string, jwks_uri: string}
     */
    public function getConfiguration(): array
    {
        $issuer = KeycloakConfiguration::normalizedIssuer();
        $cacheKey = self::CACHE_PREFIX.hash('sha256', $issuer);
        $ttl = (int) config('keycloak.discovery_cache_ttl', 3600);

        /** @var array{issuer: string, jwks_uri: string}|null $cached */
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && isset($cached['jwks_uri'])) {
            return $cached;
        }

        $discoveryUrl = $issuer.'/.well-known/openid-configuration';

        $response = Http::timeout(5)
            ->acceptJson()
            ->get($discoveryUrl);

        if (! $response->successful()) {
            throw new KeycloakConfigurationException(
                'No se pudo obtener la configuración OIDC de Keycloak.',
            );
        }

        /** @var array<string, mixed> $document */
        $document = $response->json();

        $documentIssuer = rtrim(trim((string) ($document['issuer'] ?? '')), '/');
        $jwksUri = trim((string) ($document['jwks_uri'] ?? ''));

        if ($documentIssuer !== $issuer) {
            throw new KeycloakConfigurationException(
                'El issuer del documento OIDC no coincide con KEYCLOAK_ISSUER.',
            );
        }

        if ($jwksUri === '') {
            throw new KeycloakConfigurationException(
                'El documento OIDC no incluye jwks_uri.',
            );
        }

        $configuration = [
            'issuer' => $documentIssuer,
            'jwks_uri' => $jwksUri,
        ];

        Cache::put($cacheKey, $configuration, $ttl);

        return $configuration;
    }

    public function getJwksUri(): string
    {
        return $this->getConfiguration()['jwks_uri'];
    }

    public function forgetCache(): void
    {
        $issuer = KeycloakConfiguration::normalizedIssuer();
        Cache::forget(self::CACHE_PREFIX.hash('sha256', $issuer));
    }

    public static function cacheKeyForIssuer(string $issuer): string
    {
        return self::CACHE_PREFIX.hash('sha256', rtrim($issuer, '/'));
    }
}
