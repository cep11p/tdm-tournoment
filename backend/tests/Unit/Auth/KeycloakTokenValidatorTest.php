<?php

namespace Tests\Unit\Auth;

use App\Support\Auth\JwksRepository;
use App\Support\Auth\KeycloakTokenValidator;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\KeycloakTestKeys;
use Tests\Support\KeycloakTestSupport;
use Tests\TestCase;

class KeycloakTokenValidatorTest extends TestCase
{
    private KeycloakTokenValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        KeycloakTestSupport::setUp();
        KeycloakTestSupport::primeJwksCache();

        $this->validator = app(KeycloakTokenValidator::class);
    }

    protected function tearDown(): void
    {
        JWT::$timestamp = null;

        parent::tearDown();
    }

    public function test_accepts_valid_token(): void
    {
        $token = KeycloakTestKeys::signToken([
            'sub' => 'opaque-subject-123',
            'email' => 'usuario@example.com',
            'name' => 'Usuario de prueba',
            'realm_access' => ['roles' => ['organizer']],
        ]);

        $identity = $this->validator->validate($token);

        $this->assertSame('opaque-subject-123', $identity->subject);
        $this->assertSame('usuario@example.com', $identity->email);
        $this->assertSame('Usuario de prueba', $identity->name);
        $this->assertSame(['organizer'], $identity->roles);
    }

    public function test_rejects_expired_token(): void
    {
        JWT::$timestamp = time() + 7200;

        $token = KeycloakTestKeys::signToken([
            'exp' => time() + 3600,
        ]);

        $this->expectException(\App\Support\Auth\Exceptions\TokenAuthenticationException::class);

        $this->validator->validate($token);
    }

    public function test_accepts_token_within_clock_skew(): void
    {
        config(['keycloak.clock_skew' => 120]);
        JWT::$timestamp = time() + 30;

        $token = KeycloakTestKeys::signToken([
            'nbf' => time() + 60,
        ]);

        $identity = $this->validator->validate($token);

        $this->assertSame('test-subject-1', $identity->subject);
    }

    public function test_rejects_incorrect_issuer(): void
    {
        $token = KeycloakTestKeys::signToken([
            'iss' => 'http://evil.test/realms/other',
        ]);

        $this->expectException(\App\Support\Auth\Exceptions\TokenAuthenticationException::class);

        $this->validator->validate($token);
    }

    public function test_rejects_incorrect_audience(): void
    {
        $token = KeycloakTestKeys::signToken([
            'aud' => 'other-api',
        ]);

        $this->expectException(\App\Support\Auth\Exceptions\TokenAuthenticationException::class);

        $this->validator->validate($token);
    }

    public function test_accepts_audience_array(): void
    {
        $token = KeycloakTestKeys::signToken([
            'aud' => ['account', KeycloakTestKeys::apiAudience()],
        ]);

        $identity = $this->validator->validate($token);

        $this->assertSame('test-subject-1', $identity->subject);
    }

    public function test_rejects_missing_sub(): void
    {
        $token = KeycloakTestKeys::signToken([
            'sub' => '',
        ]);

        $this->expectException(\App\Support\Auth\Exceptions\TokenAuthenticationException::class);

        $this->validator->validate($token);
    }

    #[DataProvider('disallowedAlgorithmProvider')]
    public function test_rejects_disallowed_algorithm(string $algorithm): void
    {
        if ($algorithm === 'none') {
            $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_'), '=');
            $payload = rtrim(strtr(base64_encode(json_encode([
                'iss' => KeycloakTestKeys::issuer(),
                'aud' => KeycloakTestKeys::apiAudience(),
                'sub' => 'test-subject-1',
                'exp' => time() + 3600,
            ])), '+/', '-_'), '=');
            $token = $header.'.'.$payload.'.';

            $this->expectException(\App\Support\Auth\Exceptions\TokenAuthenticationException::class);

            $this->validator->validate($token);

            return;
        }

        $token = KeycloakTestKeys::signToken([], ['alg' => $algorithm]);

        $this->expectException(\App\Support\Auth\Exceptions\TokenAuthenticationException::class);

        $this->validator->validate($token);
    }

    public static function disallowedAlgorithmProvider(): array
    {
        return [
            'HS256' => ['HS256'],
            'none' => ['none'],
        ];
    }

    public function test_rejects_invalid_signature(): void
    {
        $token = KeycloakTestKeys::signToken([]);
        $segments = explode('.', $token);
        $segments[2] = strrev($segments[2]);
        $tampered = implode('.', $segments);

        $this->expectException(\App\Support\Auth\Exceptions\TokenAuthenticationException::class);

        $this->validator->validate($tampered);
    }

    public function test_rejects_missing_kid(): void
    {
        $token = KeycloakTestKeys::signToken([], ['kid' => '']);

        $this->expectException(\App\Support\Auth\Exceptions\TokenAuthenticationException::class);

        $this->validator->validate($token);
    }

    public function test_refreshes_jwks_when_kid_is_unknown(): void
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

        $token = KeycloakTestKeys::signToken([], ['kid' => $kid]);

        $identity = app(KeycloakTokenValidator::class)->validate($token);

        $this->assertSame('test-subject-1', $identity->subject);
        $this->assertSame(2, $jwksRequests);
    }

    public function test_fails_when_kid_remains_unknown_after_refresh(): void
    {
        Cache::flush();
        KeycloakTestSupport::primeOidcCache();

        $issuer = KeycloakTestKeys::issuer();
        $jwksUri = $issuer.'/protocol/openid-connect/certs';

        Http::fake([
            $issuer.'/.well-known/openid-configuration' => Http::response(KeycloakTestKeys::discoveryDocument()),
            $jwksUri => Http::response(KeycloakTestKeys::jwks()),
        ]);

        $token = KeycloakTestKeys::signToken([], ['kid' => 'missing-kid']);

        $this->expectException(\App\Support\Auth\Exceptions\TokenAuthenticationException::class);

        app(KeycloakTokenValidator::class)->validate($token);
    }
}
