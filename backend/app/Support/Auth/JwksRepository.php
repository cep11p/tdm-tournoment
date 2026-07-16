<?php

namespace App\Support\Auth;

use App\Support\Auth\Exceptions\KeycloakConfigurationException;
use App\Support\Auth\Exceptions\TokenAuthenticationException;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class JwksRepository
{
    private const CACHE_PREFIX = 'keycloak:jwks:';

    public function __construct(
        private readonly OidcConfigurationRepository $oidcConfiguration,
    ) {}

    public function resolvePublicKey(string $kid): Key
    {
        if ($kid === '') {
            throw new KeycloakConfigurationException('El token no incluye kid.');
        }

        $key = $this->findCachedKey($kid);

        if ($key instanceof Key) {
            return $key;
        }

        $this->refreshJwks();

        $key = $this->findCachedKey($kid);

        if ($key instanceof Key) {
            return $key;
        }

        $this->refreshJwks(force: true);

        $key = $this->findCachedKey($kid);

        if ($key instanceof Key) {
            return $key;
        }

        throw new TokenAuthenticationException('No se encontró la clave pública para el kid del token.');
    }

    public function forgetCache(): void
    {
        Cache::forget($this->cacheKey());
    }

    public function cacheKey(): string
    {
        return self::CACHE_PREFIX.hash('sha256', $this->oidcConfiguration->getJwksUri());
    }

    private function findCachedKey(string $kid): ?Key
    {
        /** @var array<string, mixed>|null $jwks */
        $jwks = Cache::get($this->cacheKey());

        if (! is_array($jwks)) {
            return null;
        }

        try {
            /** @var array<string, Key> $keys */
            $keys = JWK::parseKeySet($jwks, 'RS256');
        } catch (\Throwable) {
            return null;
        }

        return $keys[$kid] ?? null;
    }

    private function refreshJwks(bool $force = false): void
    {
        $cacheKey = $this->cacheKey();

        if (! $force && Cache::has($cacheKey)) {
            return;
        }

        $jwksUri = $this->oidcConfiguration->getJwksUri();
        $ttl = (int) config('keycloak.jwks_cache_ttl', 3600);

        $requestUrl = $force
            ? $this->cacheBustedJwksUri($jwksUri)
            : $jwksUri;

        $response = Http::timeout(5)
            ->acceptJson()
            ->get($requestUrl);

        if (! $response->successful()) {
            throw new KeycloakConfigurationException('No se pudo obtener el JWKS de Keycloak.');
        }

        /** @var array<string, mixed>|null $jwks */
        $jwks = $response->json();

        if (! is_array($jwks) || ! isset($jwks['keys']) || ! is_array($jwks['keys']) || $jwks['keys'] === []) {
            throw new KeycloakConfigurationException('El JWKS de Keycloak es inválido o está vacío.');
        }

        if ($force) {
            /** @var array<string, mixed>|null $cached */
            $cached = Cache::get($cacheKey);

            if (is_array($cached) && isset($cached['keys']) && is_array($cached['keys'])) {
                $jwks = $this->mergeJwks($cached, $jwks);
            }
        }

        Cache::put($cacheKey, $jwks, $ttl);
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeJwks(array $existing, array $incoming): array
    {
        $byKid = [];

        foreach (array_merge($existing['keys'] ?? [], $incoming['keys'] ?? []) as $jwk) {
            if (! is_array($jwk)) {
                continue;
            }

            $kid = isset($jwk['kid']) ? (string) $jwk['kid'] : null;

            if ($kid === null || $kid === '') {
                continue;
            }

            $byKid[$kid] = $jwk;
        }

        return ['keys' => array_values($byKid)];
    }

    private function cacheBustedJwksUri(string $jwksUri): string
    {
        $separator = str_contains($jwksUri, '?') ? '&' : '?';

        return $jwksUri.$separator.'_kc_refresh='.microtime(true);
    }
}
