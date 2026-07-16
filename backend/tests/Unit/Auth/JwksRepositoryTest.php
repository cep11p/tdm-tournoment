<?php

namespace Tests\Unit\Auth;

use App\Support\Auth\JwksRepository;
use App\Support\Auth\KeycloakTokenValidator;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\KeycloakTestKeys;
use Tests\Support\KeycloakTestSupport;
use Tests\TestCase;

class JwksRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        KeycloakTestSupport::setUp();
        KeycloakTestSupport::primeOidcCache();
    }

    public function test_force_refresh_overwrites_cached_jwks(): void
    {
        Cache::flush();
        KeycloakTestSupport::primeOidcCache();

        $issuer = KeycloakTestKeys::issuer();
        $jwksUri = $issuer.'/protocol/openid-connect/certs';
        $kid = 'rotated-key';
        $rotatedJwks = KeycloakTestKeys::jwks();
        $rotatedJwks['keys'][0]['kid'] = $kid;
        $jwksRequests = 0;

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($issuer, $jwksUri, $rotatedJwks, &$jwksRequests) {
            if ($request->url() === $issuer.'/.well-known/openid-configuration') {
                return Http::response(KeycloakTestKeys::discoveryDocument());
            }

            if (str_starts_with($request->url(), $jwksUri)) {
                $jwksRequests++;

                return Http::response($jwksRequests === 1 ? KeycloakTestKeys::jwks() : $rotatedJwks);
            }

            return Http::response([], 404);
        });

        $repository = app(JwksRepository::class);
        $repository->forgetCache();

        $reflection = new \ReflectionClass($repository);
        $refresh = $reflection->getMethod('refreshJwks');
        $refresh->setAccessible(true);
        $refresh->invoke($repository, false);
        $refresh->invoke($repository, true);

        $this->assertSame(2, $jwksRequests, 'Solicitudes JWKS realizadas: '.$jwksRequests);

        /** @var array<string, mixed> $cached */
        $cached = Cache::get($repository->cacheKey());

        $this->assertContains($kid, array_column($cached['keys'] ?? [], 'kid'));

        $keys = \Firebase\JWT\JWK::parseKeySet($cached, 'RS256');

        $this->assertArrayHasKey($kid, $keys);
    }

    public function test_refreshes_jwks_cache_when_kid_is_missing(): void
    {
        Cache::flush();
        KeycloakTestSupport::primeOidcCache();

        $issuer = KeycloakTestKeys::issuer();
        $jwksUri = $issuer.'/protocol/openid-connect/certs';
        $kid = 'rotated-key';
        $rotatedJwks = KeycloakTestKeys::jwks();
        $rotatedJwks['keys'][0]['kid'] = $kid;
        $jwksRequests = 0;

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($issuer, $jwksUri, $rotatedJwks, &$jwksRequests) {
            if ($request->url() === $issuer.'/.well-known/openid-configuration') {
                return Http::response(KeycloakTestKeys::discoveryDocument());
            }

            if (str_starts_with($request->url(), $jwksUri)) {
                $jwksRequests++;

                return Http::response($jwksRequests === 1 ? KeycloakTestKeys::jwks() : $rotatedJwks);
            }

            return Http::response([], 404);
        });

        $repository = app(JwksRepository::class);
        $repository->forgetCache();

        try {
            $key = $repository->resolvePublicKey($kid);
        } catch (\App\Support\Auth\Exceptions\TokenAuthenticationException $exception) {
            $this->assertSame(2, $jwksRequests, 'Se esperaban dos solicitudes JWKS, se hicieron: '.$jwksRequests);

            throw $exception;
        }

        $this->assertSame('RS256', $key->getAlgorithm());
        $this->assertSame(2, $jwksRequests);
    }

    public function test_validator_accepts_token_when_cached_jwks_contains_kid(): void
    {
        $kid = 'rotated-key';
        $rotatedJwks = KeycloakTestKeys::jwks();
        $rotatedJwks['keys'][0]['kid'] = $kid;

        $repository = app(JwksRepository::class);
        $repository->forgetCache();
        Cache::put($repository->cacheKey(), $rotatedJwks, 3600);

        $token = KeycloakTestKeys::signToken([], ['kid' => $kid]);
        $segments = explode('.', $token);
        $header = json_decode(JWT::urlsafeB64Decode($segments[0]), true);

        $this->assertSame($kid, $header['kid'] ?? null);

        $identity = app(KeycloakTokenValidator::class)->validate($token);

        $this->assertSame('test-subject-1', $identity->subject);
    }
}
