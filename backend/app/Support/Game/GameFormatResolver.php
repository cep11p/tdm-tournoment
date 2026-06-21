<?php

namespace App\Support\Game;

use App\Models\Competition;
use Illuminate\Validation\ValidationException;

final class GameFormatResolver
{
    /**
     * @return array{best_of: int, sets_to_win: int}
     */
    public static function resolveForGroup(Competition $competition): array
    {
        return self::fromBestOf((int) $competition->group_stage_best_of);
    }

    /**
     * @return array{best_of: int, sets_to_win: int}
     */
    public static function resolveForBracketRound(Competition $competition, string $roundLabel): array
    {
        $bestOf = match ($roundLabel) {
            'Semifinal' => (int) $competition->semifinal_best_of,
            'Final' => (int) $competition->final_best_of,
            default => (int) $competition->knockout_stage_best_of,
        };

        return self::fromBestOf($bestOf);
    }

    /**
     * @return array{best_of: int, sets_to_win: int}
     */
    public static function fromLegacySetsToWin(int $setsToWin): array
    {
        return self::fromBestOf(max(1, ($setsToWin * 2) - 1));
    }

    /**
     * @return array{best_of: int, sets_to_win: int}
     */
    public static function fromBestOf(int $bestOf): array
    {
        if ($bestOf < 1 || $bestOf % 2 === 0) {
            throw ValidationException::withMessages([
                'best_of' => ['El formato debe ser un número impar positivo (1, 3, 5 o 7).'],
            ]);
        }

        return [
            'best_of' => $bestOf,
            'sets_to_win' => intdiv($bestOf, 2) + 1,
        ];
    }
}
