<?php

namespace App\Support\Group;

use InvalidArgumentException;

final class GroupSheetNumbering
{
    /**
     * Asigna números de planilla 1..N por competition_entry_id ASC.
     *
     * El orden de entrada no importa: el mapa resultante es estable.
     *
     * @param  list<int>  $competitionEntryIds
     * @return array<int, int>  competition_entry_id => sheet_number (1-based)
     */
    public static function forCompetitionEntryIds(array $competitionEntryIds): array
    {
        $ids = array_map(static fn (mixed $id): int => (int) $id, $competitionEntryIds);

        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException(
                'Los competition_entry_id de planilla no pueden repetirse.',
            );
        }

        sort($ids);

        $numbers = [];

        foreach ($ids as $index => $id) {
            $numbers[$id] = $index + 1;
        }

        return $numbers;
    }
}
