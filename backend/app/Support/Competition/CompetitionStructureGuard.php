<?php

namespace App\Support\Competition;

use App\Enums\GameStatus;
use App\Models\Competition;
use Illuminate\Validation\ValidationException;

final class CompetitionStructureGuard
{
    public const LOCK_MESSAGE = 'No se puede modificar la estructura de la competencia porque ya tiene partidos iniciados o finalizados.';

    public static function hasRealStartedGames(Competition $competition): bool
    {
        return $competition->games()
            ->where(function ($query): void {
                $query->where('is_bye', false)
                    ->orWhereNull('is_bye');
            })
            ->whereIn('status', [GameStatus::InProgress, GameStatus::Finished])
            ->exists();
    }

    public static function isStructureEditable(Competition $competition): bool
    {
        return ! self::hasRealStartedGames($competition);
    }

    public static function structureLockReason(Competition $competition): ?string
    {
        return self::isStructureEditable($competition) ? null : self::LOCK_MESSAGE;
    }

    public static function ensureEditable(Competition $competition, string $field = 'competition'): void
    {
        if (! self::isStructureEditable($competition)) {
            throw ValidationException::withMessages([
                $field => [self::LOCK_MESSAGE],
            ]);
        }
    }
}
