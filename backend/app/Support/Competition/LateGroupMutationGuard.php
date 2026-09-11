<?php

namespace App\Support\Competition;

use App\Models\Competition;
use Illuminate\Validation\ValidationException;

final class LateGroupMutationGuard
{
    public const LOCK_MESSAGE = 'No se pueden modificar los grupos porque la llave eliminatoria ya fue generada.';

    public static function hasGeneratedBracket(Competition $competition): bool
    {
        return $competition->brackets()->exists();
    }

    public static function isAllowed(Competition $competition): bool
    {
        if (self::hasGeneratedBracket($competition)) {
            return false;
        }

        if ($competition->isTeam()) {
            return CompetitionStructureGuard::isStructureEditable($competition);
        }

        return true;
    }

    public static function lockReason(Competition $competition): ?string
    {
        if (self::hasGeneratedBracket($competition)) {
            return self::LOCK_MESSAGE;
        }

        if ($competition->isTeam()) {
            return CompetitionStructureGuard::structureLockReason($competition);
        }

        return null;
    }

    public static function ensureAllowed(Competition $competition, string $field = 'competition'): void
    {
        if (self::hasGeneratedBracket($competition)) {
            throw ValidationException::withMessages([
                $field => [self::LOCK_MESSAGE],
            ]);
        }

        if ($competition->isTeam()) {
            CompetitionStructureGuard::ensureEditable($competition, $field);
        }
    }
}
