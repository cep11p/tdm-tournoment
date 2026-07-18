<?php

namespace Tests\Unit\Auth;

use App\Support\Auth\KeycloakConfiguration;
use App\Support\Auth\Exceptions\KeycloakConfigurationException;
use Tests\TestCase;

class KeycloakConfigurationTest extends TestCase
{
    protected function tearDown(): void
    {
        config([
            'keycloak.issuer' => null,
            'keycloak.oidc_base_url' => null,
            'keycloak.api_audience' => null,
        ]);

        parent::tearDown();
    }

    public function test_oidc_base_url_falls_back_to_issuer_when_unset(): void
    {
        config([
            'keycloak.issuer' => 'http://localhost:8180/realms/tdm',
            'keycloak.oidc_base_url' => null,
            'keycloak.api_audience' => 'tdm-api',
        ]);

        $this->assertSame(
            'http://localhost:8180/realms/tdm',
            KeycloakConfiguration::oidcBaseUrl(),
        );
    }

    public function test_oidc_base_url_uses_explicit_internal_url(): void
    {
        config([
            'keycloak.issuer' => 'http://localhost:8180/realms/tdm',
            'keycloak.oidc_base_url' => 'http://keycloak:8080/realms/tdm',
            'keycloak.api_audience' => 'tdm-api',
        ]);

        $this->assertSame(
            'http://keycloak:8080/realms/tdm',
            KeycloakConfiguration::oidcBaseUrl(),
        );
    }

    public function test_resolve_internal_jwks_uri_rewrites_host_when_oidc_base_differs(): void
    {
        config([
            'keycloak.issuer' => 'http://localhost:8180/realms/tdm',
            'keycloak.oidc_base_url' => 'http://keycloak:8080/realms/tdm',
            'keycloak.api_audience' => 'tdm-api',
        ]);

        $publicJwksUri = 'http://localhost:8180/realms/tdm/protocol/openid-connect/certs';

        $this->assertSame(
            'http://keycloak:8080/realms/tdm/protocol/openid-connect/certs',
            KeycloakConfiguration::resolveInternalJwksUri($publicJwksUri),
        );
    }

    public function test_resolve_internal_jwks_uri_keeps_public_url_when_base_matches_issuer(): void
    {
        config([
            'keycloak.issuer' => 'http://localhost:8180/realms/tdm',
            'keycloak.oidc_base_url' => 'http://localhost:8180/realms/tdm',
            'keycloak.api_audience' => 'tdm-api',
        ]);

        $publicJwksUri = 'http://localhost:8180/realms/tdm/protocol/openid-connect/certs';

        $this->assertSame(
            $publicJwksUri,
            KeycloakConfiguration::resolveInternalJwksUri($publicJwksUri),
        );
    }

    public function test_resolve_internal_jwks_uri_rejects_unrelated_paths(): void
    {
        config([
            'keycloak.issuer' => 'http://localhost:8180/realms/tdm',
            'keycloak.oidc_base_url' => 'http://keycloak:8080/realms/tdm',
            'keycloak.api_audience' => 'tdm-api',
        ]);

        $this->expectException(KeycloakConfigurationException::class);

        KeycloakConfiguration::resolveInternalJwksUri('http://evil.test/realms/tdm/protocol/openid-connect/certs');
    }
}
