<?php

namespace App\Support\Bracket;

use App\Data\Competition\GroupQualifierData;
use Illuminate\Support\Collection;

final class GroupQualifierCanonicalOrder
{
    /**
     * Orden canónico de grupos para resolver el groupIndex de una plantilla:
     * agrupar por groupId, ordenar por groupName y reindexar a 0..N-1.
     *
     * No hay letra persistida ni group_index en base de datos: el índice
     * de la plantilla se interpreta exclusivamente contra este orden.
     *
     * @param  Collection<int, GroupQualifierData>  $qualifiers
     * @return Collection<int, Collection<int, GroupQualifierData>>
     */
    public static function groups(Collection $qualifiers): Collection
    {
        return $qualifiers
            ->groupBy(fn (GroupQualifierData $qualifier): int => $qualifier->groupId)
            ->sortBy(
                fn (Collection $groupQualifiers): string => $groupQualifiers->first()->groupName,
            )
            ->values();
    }
}
