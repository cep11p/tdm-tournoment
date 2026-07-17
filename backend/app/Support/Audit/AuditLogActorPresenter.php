<?php

namespace App\Support\Audit;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

final class AuditLogActorPresenter
{
    /**
     * @return array{
     *     id?: int,
     *     name?: string,
     *     email?: string|null,
     *     keycloak_id?: string|null
     * }|null
     */
    public static function present(Activity $activity): ?array
    {
        $causer = $activity->relationLoaded('causer') ? $activity->causer : null;
        $properties = $activity->properties?->toArray() ?? [];
        $keycloakId = data_get($properties, 'actor.keycloak_id');

        if ($causer instanceof User) {
            return [
                'id' => $causer->id,
                'name' => $causer->name,
                'email' => $causer->email,
                'keycloak_id' => $causer->keycloak_id ?? (is_string($keycloakId) ? $keycloakId : null),
            ];
        }

        if (is_string($keycloakId) && $keycloakId !== '') {
            return [
                'keycloak_id' => $keycloakId,
            ];
        }

        return null;
    }
}
