<?php

namespace Tests\Support;

use Firebase\JWT\JWT;

final class KeycloakTestKeys
{
    private const KID = 'test-rsa-key';

    /** @var array{private: string, public: string, kid: string}|null */
    private static ?array $material = null;

    public static function kid(): string
    {
        self::boot();

        return self::$material['kid'];
    }

    public static function privateKeyPem(): string
    {
        self::boot();

        return self::$material['private'];
    }

    public static function issuer(): string
    {
        return 'http://keycloak.test/realms/tdm';
    }

    public static function apiAudience(): string
    {
        return 'tdm-api';
    }

    public static function discoveryDocument(): array
    {
        $issuer = self::issuer();

        return [
            'issuer' => $issuer,
            'jwks_uri' => $issuer.'/protocol/openid-connect/certs',
        ];
    }

    public static function jwks(): array
    {
        self::boot();

        $details = openssl_pkey_get_details(openssl_pkey_get_public(self::$material['public']));

        if ($details === false || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new \RuntimeException('No se pudo derivar el JWKS de prueba.');
        }

        return [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => self::KID,
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => self::base64UrlEncode($details['rsa']['n']),
                'e' => self::base64UrlEncode($details['rsa']['e']),
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $header
     */
    public static function signToken(array $claims, array $header = []): string
    {
        self::boot();

        $payload = array_merge([
            'iss' => self::issuer(),
            'aud' => self::apiAudience(),
            'sub' => 'test-subject-1',
            'exp' => time() + 3600,
            'iat' => time(),
        ], $claims);

        $headers = array_merge([
            'typ' => 'JWT',
            'alg' => 'RS256',
        ], $header);

        $algorithm = (string) ($headers['alg'] ?? 'RS256');
        $kid = self::KID;

        if (array_key_exists('kid', $header)) {
            $kid = $header['kid'] !== '' ? (string) $header['kid'] : null;
            unset($headers['kid']);
        }

        $headers['alg'] = $algorithm;

        return JWT::encode($payload, self::$material['private'], $algorithm, $kid, $headers);
    }

    public static function configureHttpFakes(): void
    {
        $issuer = self::issuer();

        \Illuminate\Support\Facades\Http::fake([
            $issuer.'/.well-known/openid-configuration' => \Illuminate\Support\Facades\Http::response(
                self::discoveryDocument(),
            ),
            $issuer.'/protocol/openid-connect/certs' => \Illuminate\Support\Facades\Http::response(
                self::jwks(),
            ),
        ]);
    }

    public static function applyConfig(): void
    {
        config([
            'keycloak.issuer' => self::issuer(),
            'keycloak.oidc_base_url' => self::issuer(),
            'keycloak.api_audience' => self::apiAudience(),
            'keycloak.frontend_client_id' => 'tdm-frontend',
            'keycloak.discovery_cache_ttl' => 3600,
            'keycloak.jwks_cache_ttl' => 3600,
            'keycloak.clock_skew' => 60,
            'keycloak.allowed_algorithms' => ['RS256'],
        ]);
    }

    public static function boot(): void
    {
        if (self::$material !== null) {
            return;
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new \RuntimeException('No se pudo generar el par RSA de prueba.');
        }

        $exported = openssl_pkey_export($resource, $privateKey);

        if ($exported === false) {
            throw new \RuntimeException('No se pudo exportar la clave privada de prueba.');
        }

        $details = openssl_pkey_get_details($resource);

        if ($details === false || ! isset($details['key'])) {
            throw new \RuntimeException('No se pudo obtener la clave pública de prueba.');
        }

        self::$material = [
            'private' => $privateKey,
            'public' => $details['key'],
            'kid' => self::KID,
        ];
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
