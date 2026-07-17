<?php

namespace Tests\Support;

use Firebase\JWT\JWT;

trait ActsAsKeycloakUser
{
    private bool $keycloakBootstrapped = false;

    protected function bootstrapKeycloak(): void
    {
        if ($this->keycloakBootstrapped) {
            return;
        }

        KeycloakTestSupport::setUp();
        KeycloakTestSupport::primeJwksCache();
        $this->keycloakBootstrapped = true;
    }

    /**
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $claims
     * @return array<string, string>
     */
    public function keycloakAuthHeaders(array $roles = ['organizer'], array $claims = []): array
    {
        $this->bootstrapKeycloak();

        $token = KeycloakTestKeys::signToken(array_merge([
            'sub' => 'test-subject-1',
            'email' => 'test@example.com',
            'name' => 'Usuario de prueba',
            'realm_access' => ['roles' => $roles],
        ], $claims));

        return [
            'Authorization' => 'Bearer '.$token,
        ];
    }

    /**
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $claims
     */
    protected function actingAsKeycloak(array $roles = ['organizer'], array $claims = []): static
    {
        return $this->withHeaders($this->keycloakAuthHeaders($roles, $claims));
    }

    protected function resetKeycloakClock(): void
    {
        JWT::$timestamp = null;
    }
}
