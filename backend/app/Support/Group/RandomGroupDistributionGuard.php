<?php

namespace App\Support\Group;

use Illuminate\Validation\ValidationException;

final class RandomGroupDistributionGuard
{
    public static function maxGroupsCount(int $playerCount): int
    {
        return intdiv($playerCount, 2);
    }

    public static function isValid(int $playerCount, int $groupsCount): bool
    {
        return $playerCount >= $groupsCount * 2;
    }

    public static function ensureValid(int $playerCount, int $groupsCount, string $field = 'groups_count'): void
    {
        if (self::isValid($playerCount, $groupsCount)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => [self::validationMessage($playerCount, $groupsCount)],
        ]);
    }

    public static function validationMessage(int $playerCount, int $groupsCount): string
    {
        $maxGroups = self::maxGroupsCount($playerCount);

        $generateSubject = $groupsCount === 1
            ? 'No se puede generar 1 grupo'
            : sprintf('No se pueden generar %d grupos', $groupsCount);

        $playersLabel = $playerCount === 1 ? '1 jugador' : sprintf('%d jugadores', $playerCount);

        $maxLabel = $maxGroups === 1
            ? '1 grupo'
            : sprintf('%d grupos', $maxGroups);

        return sprintf(
            '%s con %s porque uno de los grupos quedaría con un solo integrante. Seleccioná un máximo de %s.',
            $generateSubject,
            $playersLabel,
            $maxLabel,
        );
    }
}
