<?php

namespace App\Support\Auth;

use App\Support\Auth\Exceptions\TokenAuthenticationException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;
use UnexpectedValueException;

final class KeycloakTokenValidator
{
    public function __construct(
        private readonly JwksRepository $jwksRepository,
        private readonly KeycloakRoleExtractor $roleExtractor,
    ) {}

    public function validate(string $token): AuthenticatedIdentity
    {
        KeycloakConfiguration::ensureConfigured();

        JWT::$leeway = (int) config('keycloak.clock_skew', 60);

        try {
            $header = $this->parseHeader($token);
            $kid = trim((string) ($header->kid ?? ''));
            $algorithm = trim((string) ($header->alg ?? ''));

            $this->assertAllowedAlgorithm($algorithm);

            if ($kid === '') {
                throw new TokenAuthenticationException('Token sin kid.');
            }

            $publicKey = $this->jwksRepository->resolvePublicKey($kid);

            if ($publicKey->getAlgorithm() !== $algorithm) {
                throw new TokenAuthenticationException('Algoritmo del token no permitido.');
            }

            $payload = JWT::decode($token, $publicKey);

            $this->assertIssuer($payload);
            $this->assertAudience($payload);
            $this->assertSubject($payload);

            return $this->mapIdentity($payload);
        } catch (TokenAuthenticationException $exception) {
            $this->logFailure($exception);

            throw $exception;
        } catch (ExpiredException|BeforeValidException|SignatureInvalidException|UnexpectedValueException $exception) {
            $this->logFailure($exception);

            throw new TokenAuthenticationException($exception->getMessage(), previous: $exception);
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            throw new TokenAuthenticationException('Token inválido.', previous: $exception);
        }
    }

    private function parseHeader(string $token): stdClass
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new TokenAuthenticationException('Formato de token inválido.');
        }

        $headerJson = JWT::urlsafeB64Decode($segments[0]);
        $header = JWT::jsonDecode($headerJson);

        if (! $header instanceof stdClass) {
            throw new TokenAuthenticationException('Encabezado de token inválido.');
        }

        return $header;
    }

    private function assertAllowedAlgorithm(string $algorithm): void
    {
        /** @var list<string> $allowed */
        $allowed = config('keycloak.allowed_algorithms', ['RS256']);

        if ($algorithm === '' || ! in_array($algorithm, $allowed, true)) {
            throw new TokenAuthenticationException('Algoritmo de token no permitido.');
        }
    }

    private function assertIssuer(stdClass $payload): void
    {
        $expectedIssuer = KeycloakConfiguration::normalizedIssuer();
        $tokenIssuer = rtrim(trim((string) ($payload->iss ?? '')), '/');

        if ($tokenIssuer !== $expectedIssuer) {
            throw new TokenAuthenticationException('Issuer del token inválido.');
        }
    }

    private function assertAudience(stdClass $payload): void
    {
        $expectedAudience = KeycloakConfiguration::apiAudience();
        $audiences = $this->normalizeAudience($payload->aud ?? null);

        if (! in_array($expectedAudience, $audiences, true)) {
            throw new TokenAuthenticationException('Audiencia del token inválida.');
        }
    }

    /**
     * @return list<string>
     */
    private function normalizeAudience(mixed $audience): array
    {
        if (is_string($audience) && $audience !== '') {
            return [$audience];
        }

        if (! is_array($audience)) {
            return [];
        }

        $normalized = [];

        foreach ($audience as $value) {
            if (is_string($value) && $value !== '') {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    private function assertSubject(stdClass $payload): void
    {
        $subject = trim((string) ($payload->sub ?? ''));

        if ($subject === '') {
            throw new TokenAuthenticationException('Token sin sub.');
        }
    }

    private function mapIdentity(stdClass $payload): AuthenticatedIdentity
    {
        $email = isset($payload->email) && is_string($payload->email) && $payload->email !== ''
            ? $payload->email
            : null;

        $name = isset($payload->name) && is_string($payload->name) && $payload->name !== ''
            ? $payload->name
            : null;

        $preferredUsername = isset($payload->preferred_username)
            && is_string($payload->preferred_username)
            && $payload->preferred_username !== ''
            ? $payload->preferred_username
            : null;

        return new AuthenticatedIdentity(
            subject: trim((string) $payload->sub),
            email: $email,
            name: $name,
            preferredUsername: $preferredUsername,
            roles: $this->roleExtractor->extract($payload),
            claims: json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    private function logFailure(Throwable $exception): void
    {
        Log::debug('Keycloak token validation failed.', [
            'reason' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);
    }
}
