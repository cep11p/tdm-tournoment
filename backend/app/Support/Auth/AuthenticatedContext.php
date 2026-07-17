<?php

namespace App\Support\Auth;

use App\Enums\Permission;
use App\Models\User;

final readonly class AuthenticatedContext
{
    public const ATTRIBUTE = 'authenticated_context';

    /**
     * @param  list<Permission>  $permissions
     */
    public function __construct(
        public User $user,
        public AuthenticatedIdentity $identity,
        public array $permissions,
    ) {}

    public function has(Permission $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * @return list<string>
     */
    public function permissionValues(): array
    {
        return array_map(
            static fn (Permission $permission): string => $permission->value,
            $this->permissions,
        );
    }
}
