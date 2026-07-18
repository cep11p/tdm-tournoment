<?php

namespace Tests\Unit\Auth;

use App\Support\Auth\Exceptions\KeycloakConfigurationException;
use App\Support\Auth\KeycloakTokenValidator;
use App\Support\Auth\OidcConfigurationRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\KeycloakTestKeys;
use Tests\TestCase;

class OidcConfigurationRepositoryTest extends TestCase
{
    private const PUBLIC_ISSUER = 'http://localhost:8180/realms/tdm';

    private const INTERNAL_BASE = 'http://keycloak:8080/realms/tdm';

    protected function tearDown(): void
    {
        config([
            'keycloak.issuer' => null,
            'keycloak.oidc_base_url' => null,
            'keycloak.api_audience' => null,
        ]);

        Cache::flush();

        parent::tearDown();
    }

    public function test_uses_issuer_for_discovery_when_oidc_base_url_is_unset(): void
    {
        KeycloakTestKeys::boot();

        config([
            'keycloak.issuer' => KeycloakTestKeys::issuer(),
            'keycloak.oidc_base_url' => null,
            'keycloak.api_audience' => KeycloakTestKeys::apiAudience(),
        ]);

        Http::fake([
            KeycloakTestKeys::issuer().'/.well-known/openid-configuration' => Http::response(
                KeycloakTestKeys::discoveryDocument(),
            ),
            KeycloakTestKeys::issuer().'/protocol/openid-connect/certs' => Http::response(
                KeycloakTestKeys::jwks(),
            ),
        ]);

        $configuration = app(OidcConfigurationRepository::class)->getConfiguration();

        $this->assertSame(KeycloakTestKeys::issuer(), $configuration['issuer']);
        $this->assertSame(
            KeycloakTestKeys::issuer().'/protocol/openid-connect/certs',
            $configuration['jwks_uri'],
        );
    }

    public function test_uses_internal_base_for_discovery_and_jwks_when_configured(): void
    {
        KeycloakTestKeys::boot();

        config([
            'keycloak.issuer' => self::PUBLIC_ISSUER,
            'keycloak.oidc_base_url' => self::INTERNAL_BASE,
            'keycloak.api_audience' => KeycloakTestKeys::apiAudience(),
        ]);

        $publicDiscovery = self::PUBLIC_ISSUER.'/.well-known/openid-configuration';
        $internalDiscovery = self::INTERNAL_BASE.'/.well-known/openid-configuration';
        $internalJwks = self::INTERNAL_BASE.'/protocol/openid-connect/certs';

        Http::fake([
            $internalDiscovery => Http::response([
                'issuer' => self::PUBLIC_ISSUER,
                'jwks_uri' => self::PUBLIC_ISSUER.'/protocol/openid-connect/certs',
            ]),
            $internalJwks => Http::response(KeycloakTestKeys::jwks()),
        ]);

        $configuration = app(OidcConfigurationRepository::class)->getConfiguration();

        $this->assertSame(self::PUBLIC_ISSUER, $configuration['issuer']);
        $this->assertSame($internalJwks, $configuration['jwks_uri']);

        Http::assertSent(function ($request) use ($internalDiscovery, $publicDiscovery) {
            return $request->url() === $internalDiscovery
                && $request->url() !== $publicDiscovery;
        });
    }

    public function test_rejects_discovery_when_document_issuer_differs_from_configured_issuer(): void
    {
        config([
            'keycloak.issuer' => self::PUBLIC_ISSUER,
            'keycloak.oidc_base_url' => self::INTERNAL_BASE,
            'keycloak.api_audience' => KeycloakTestKeys::apiAudience(),
        ]);

        Http::fake([
            self::INTERNAL_BASE.'/.well-known/openid-configuration' => Http::response([
                'issuer' => 'http://keycloak:8080/realms/tdm',
                'jwks_uri' => 'http://keycloak:8080/realms/tdm/protocol/openid-connect/certs',
            ]),
        ]);

        $this->expectException(KeycloakConfigurationException::class);
        $this->expectExceptionMessage('El issuer del documento OIDC no coincide con KEYCLOAK_ISSUER.');

        app(OidcConfigurationRepository::class)->getConfiguration();
    }

    public function test_validator_accepts_public_issuer_and_rejects_internal_issuer(): void
    {
        KeycloakTestKeys::boot();

        config([
            'keycloak.issuer' => self::PUBLIC_ISSUER,
            'keycloak.oidc_base_url' => self::INTERNAL_BASE,
            'keycloak.api_audience' => KeycloakTestKeys::apiAudience(),
            'keycloak.allowed_algorithms' => ['RS256'],
        ]);

        Http::fake([
            self::INTERNAL_BASE.'/.well-known/openid-configuration' => Http::response([
                'issuer' => self::PUBLIC_ISSUER,
                'jwks_uri' => self::PUBLIC_ISSUER.'/protocol/openid-connect/certs',
            ]),
            self::INTERNAL_BASE.'/protocol/openid-connect/certs' => Http::response(KeycloakTestKeys::jwks()),
        ]);

        $validToken = KeycloakTestKeys::signToken([
            'iss' => self::PUBLIC_ISSUER,
            'aud' => KeycloakTestKeys::apiAudience(),
            'realm_access' => ['roles' => ['admin']],
        ]);

        $identity = app(KeycloakTokenValidator::class)->validate($validToken);

        $this->assertSame('test-subject-1', $identity->subject);
        $this->assertContains('admin', $identity->roles);

        $invalidToken = KeycloakTestKeys::signToken([
            'iss' => self::INTERNAL_BASE,
            'aud' => KeycloakTestKeys::apiAudience(),
        ]);

        $this->expectException(\App\Support\Auth\Exceptions\TokenAuthenticationException::class);
        $this->expectExceptionMessage('Issuer del token inválido.');

        app(KeycloakTokenValidator::class)->validate($invalidToken);
    }
}
