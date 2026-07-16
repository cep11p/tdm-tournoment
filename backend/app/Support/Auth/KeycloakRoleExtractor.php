<?php

namespace App\Support\Auth;

use stdClass;

final class KeycloakRoleExtractor
{
    /**
     * @return list<string>
     */
    public function extract(stdClass $payload): array
    {
        $roles = [];

        if (isset($payload->realm_access) && $payload->realm_access instanceof stdClass) {
            $roles = array_merge($roles, $this->normalizeRoles($payload->realm_access->roles ?? null));
        }

        return array_values(array_unique($roles));
    }

    /**
     * @return list<string>
     */
    private function normalizeRoles(mixed $roles): array
    {
        if (! is_array($roles)) {
            return [];
        }

        $normalized = [];

        foreach ($roles as $role) {
            if (is_string($role) && $role !== '') {
                $normalized[] = $role;
            }
        }

        return $normalized;
    }
}
