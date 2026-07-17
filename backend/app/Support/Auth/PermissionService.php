<?php

namespace App\Support\Auth;

use App\Enums\Permission;
use App\Models\User;

final class PermissionService
{
    /**
     * @param  list<string>  $roles
     * @return list<Permission>
     */
    public function resolvePermissions(array $roles): array
    {
        /** @var array<string, list<string>> $roleMap */
        $roleMap = config('permissions.roles', []);

        $resolved = [];

        foreach ($roles as $role) {
            if (! is_string($role) || $role === '') {
                continue;
            }

            if ($role === 'admin') {
                return Permission::all();
            }

            if (! isset($roleMap[$role])) {
                continue;
            }

            foreach ($roleMap[$role] as $permissionValue) {
                if (! is_string($permissionValue) || $permissionValue === '') {
                    continue;
                }

                $resolved[] = Permission::from($permissionValue);
            }
        }

        $unique = [];

        foreach ($resolved as $permission) {
            $unique[$permission->value] = $permission;
        }

        return array_values($unique);
    }

    public function contextFor(User $user, AuthenticatedIdentity $identity): AuthenticatedContext
    {
        return new AuthenticatedContext(
            user: $user,
            identity: $identity,
            permissions: $this->resolvePermissions($identity->roles),
        );
    }

    public function hasPermission(AuthenticatedIdentity $identity, Permission $permission): bool
    {
        foreach ($this->resolvePermissions($identity->roles) as $resolved) {
            if ($resolved === $permission) {
                return true;
            }
        }

        return false;
    }
}
