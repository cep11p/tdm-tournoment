<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Auth\AuthenticatedIdentity;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class SyncKeycloakUserAction
{
    public function __invoke(AuthenticatedIdentity $identity): User
    {
        $user = User::query()->where('keycloak_id', $identity->subject)->first();

        if ($user instanceof User) {
            $this->applyIdentityAttributes($user, $identity);
            $user->last_login_at = now();
            $user->save();

            return $user->refresh();
        }

        return User::query()->create([
            'keycloak_id' => $identity->subject,
            'name' => $this->resolveName($identity),
            'email' => $this->resolveEmail($identity),
            'password' => Hash::make(Str::random(64)),
            'last_login_at' => now(),
        ]);
    }

    private function applyIdentityAttributes(User $user, AuthenticatedIdentity $identity): void
    {
        if ($identity->name !== null) {
            $user->name = $identity->name;
        }

        if ($identity->email !== null) {
            $user->email = $identity->email;
        }
    }

    private function resolveName(AuthenticatedIdentity $identity): string
    {
        return $identity->name
            ?? $identity->preferredUsername
            ?? 'Usuario';
    }

    private function resolveEmail(AuthenticatedIdentity $identity): string
    {
        if ($identity->email !== null) {
            return $identity->email;
        }

        return $identity->subject.'@keycloak.local';
    }
}
