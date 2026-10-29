<?php

namespace App\Support\Group;

use App\Enums\GroupPlayerStatus;
use App\Models\Group;

final class GroupActiveEntries
{
    /**
     * Inscripciones activas del grupo (las únicas que disputan partidos nuevos),
     * ordenadas por competition_entry_id ASC.
     *
     * @return list<int>
     */
    public static function ids(Group $group): array
    {
        return $group->groupEntries()
            ->where('status', GroupPlayerStatus::Active)
            ->orderBy('competition_entry_id')
            ->pluck('competition_entry_id')
            ->map(fn ($entryId): int => (int) $entryId)
            ->values()
            ->all();
    }

    public static function count(Group $group): int
    {
        return $group->groupEntries()
            ->where('status', GroupPlayerStatus::Active)
            ->count();
    }

    /**
     * Un grupo con menos de 2 activos no requiere partidos: su único activo
     * (si lo hay) clasifica sin disputarlos.
     */
    public static function requiresSchedule(Group $group): bool
    {
        return self::count($group) >= 2;
    }

    /**
     * Grupo con al menos 2 integrantes que, por bajas, quedó con menos de 2 activos:
     * no tiene ni necesita partidos. Un grupo con menos de 2 integrantes no entra
     * aquí: sigue incompleto.
     */
    public static function skipsScheduleByWithdrawals(Group $group): bool
    {
        return $group->groupEntries()->count() >= 2 && ! self::requiresSchedule($group);
    }
}
