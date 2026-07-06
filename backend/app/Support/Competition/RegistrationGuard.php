<?php

namespace App\Support\Competition;

use App\Models\Competition;
use Illuminate\Validation\ValidationException;

final class RegistrationGuard
{
    public const LOCK_MESSAGE = 'No se pueden modificar las inscripciones porque la llave eliminatoria ya fue generada.';

    public static function hasGeneratedBracket(Competition $competition): bool
    {
        return $competition->brackets()->exists();
    }

    public static function isEditable(Competition $competition): bool
    {
        if (self::hasGeneratedBracket($competition)) {
            return false;
        }

        return CompetitionStructureGuard::isStructureEditable($competition);
    }

    public static function lockReason(Competition $competition): ?string
    {
        if (self::hasGeneratedBracket($competition)) {
            return self::LOCK_MESSAGE;
        }

        return CompetitionStructureGuard::structureLockReason($competition);
    }

    public static function ensureEditable(Competition $competition, string $field = 'competition'): void
    {
        if (self::hasGeneratedBracket($competition)) {
            throw ValidationException::withMessages([
                $field => [self::LOCK_MESSAGE],
            ]);
        }

        CompetitionStructureGuard::ensureEditable($competition, $field);
    }
}
