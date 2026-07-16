<?php

namespace Tests\Unit\Auth;

use App\Actions\Auth\SyncKeycloakUserAction;
use App\Models\User;
use App\Support\Auth\AuthenticatedIdentity;
use Tests\TestCase;

class SyncKeycloakUserActionTest extends TestCase
{
    private SyncKeycloakUserAction $syncUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncUser = app(SyncKeycloakUserAction::class);
    }

    public function test_creates_user_on_first_access(): void
    {
        $identity = new AuthenticatedIdentity(
            subject: 'kc-subject-create',
            email: 'nuevo@example.com',
            name: 'Nuevo Usuario',
            preferredUsername: 'nuevo',
            roles: ['organizer'],
            claims: [],
        );

        $user = ($this->syncUser)($identity);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'keycloak_id' => 'kc-subject-create',
            'email' => 'nuevo@example.com',
            'name' => 'Nuevo Usuario',
        ]);
        $this->assertNotNull($user->last_login_at);
    }

    public function test_reuses_and_updates_same_user_by_sub(): void
    {
        $identity = new AuthenticatedIdentity(
            subject: 'kc-subject-update',
            email: 'original@example.com',
            name: 'Nombre Original',
            preferredUsername: 'original',
            roles: ['player'],
            claims: [],
        );

        $first = ($this->syncUser)($identity);

        $updatedIdentity = new AuthenticatedIdentity(
            subject: 'kc-subject-update',
            email: 'actualizado@example.com',
            name: 'Nombre Actualizado',
            preferredUsername: 'actualizado',
            roles: ['organizer'],
            claims: [],
        );

        $second = ($this->syncUser)($updatedIdentity);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, User::query()->where('keycloak_id', 'kc-subject-update')->count());
        $this->assertDatabaseHas('users', [
            'id' => $first->id,
            'email' => 'actualizado@example.com',
            'name' => 'Nombre Actualizado',
        ]);
    }

    public function test_does_not_update_unrelated_user_by_email(): void
    {
        $existing = User::factory()->create([
            'email' => 'otro@example.com',
            'keycloak_id' => 'existing-subject',
            'name' => 'Usuario Existente',
        ]);

        $identity = new AuthenticatedIdentity(
            subject: 'brand-new-subject',
            email: 'nuevo@example.com',
            name: 'Usuario Nuevo',
            preferredUsername: null,
            roles: [],
            claims: [],
        );

        $user = ($this->syncUser)($identity);

        $this->assertNotSame($existing->id, $user->id);
        $existing->refresh();
        $this->assertSame('Usuario Existente', $existing->name);
        $this->assertSame('existing-subject', $existing->keycloak_id);
    }

    public function test_does_not_overwrite_email_with_null(): void
    {
        $identity = new AuthenticatedIdentity(
            subject: 'kc-subject-no-email',
            email: 'persistente@example.com',
            name: 'Con Email',
            preferredUsername: null,
            roles: [],
            claims: [],
        );

        $user = ($this->syncUser)($identity);

        $withoutEmail = new AuthenticatedIdentity(
            subject: 'kc-subject-no-email',
            email: null,
            name: 'Sin Email Token',
            preferredUsername: null,
            roles: [],
            claims: [],
        );

        $updated = ($this->syncUser)($withoutEmail);

        $this->assertSame($user->id, $updated->id);
        $this->assertSame('persistente@example.com', $updated->email);
        $this->assertSame('Sin Email Token', $updated->name);
    }

    public function test_uses_synthetic_email_when_token_has_no_email_on_create(): void
    {
        $identity = new AuthenticatedIdentity(
            subject: 'opaque-without-email',
            email: null,
            name: null,
            preferredUsername: 'jugador1',
            roles: [],
            claims: [],
        );

        $user = ($this->syncUser)($identity);

        $this->assertSame('opaque-without-email@keycloak.local', $user->email);
        $this->assertSame('jugador1', $user->name);
    }
}
