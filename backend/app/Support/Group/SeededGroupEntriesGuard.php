<?php

namespace App\Support\Group;

use Illuminate\Validation\ValidationException;

final class SeededGroupEntriesGuard
{
    /**
     * @param  list<int>  $eligibleEntryIds
     * @param  list<int>  $seededEntryIds
     * @return list<int>
     */
    public static function ensureValid(
        array $eligibleEntryIds,
        array $seededEntryIds,
        int $groupsCount,
        string $field = 'seeded_entry_ids',
    ): array {
        $normalizedIds = array_values(array_map(static fn (mixed $id): int => (int) $id, $seededEntryIds));

        if ($normalizedIds === []) {
            return [];
        }

        if (count($normalizedIds) !== count(array_unique($normalizedIds))) {
            throw ValidationException::withMessages([
                $field => ['Las participaciones cabeza de serie no pueden repetirse.'],
            ]);
        }

        if (count($normalizedIds) > $groupsCount) {
            throw ValidationException::withMessages([
                $field => [self::tooManyMessage(count($normalizedIds), $groupsCount)],
            ]);
        }

        $eligibleLookup = array_fill_keys(
            array_map(static fn (mixed $id): int => (int) $id, $eligibleEntryIds),
            true,
        );

        foreach ($normalizedIds as $seededEntryId) {
            if (! isset($eligibleLookup[$seededEntryId])) {
                throw ValidationException::withMessages([
                    $field => ['Una o más participaciones seleccionadas como cabeza de serie no están disponibles para armar los grupos.'],
                ]);
            }
        }

        return $normalizedIds;
    }

    public static function tooManyMessage(int $seededCount, int $groupsCount): string
    {
        $seededLabel = $seededCount === 1
            ? '1 cabeza de serie'
            : sprintf('%d cabezas de serie', $seededCount);

        $maxLabel = $groupsCount === 1
            ? '1 grupo'
            : sprintf('%d grupos', $groupsCount);

        return sprintf(
            'No se pueden seleccionar %s para %s. El máximo es %s.',
            $seededLabel,
            $maxLabel,
            $maxLabel,
        );
    }
}
