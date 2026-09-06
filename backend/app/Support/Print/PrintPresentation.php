<?php

namespace App\Support\Print;

use App\Data\Group\PrintGroupSheetData;
use Illuminate\Http\Request;

final class PrintPresentation
{
    /**
     * @return list<int>
     */
    public static function setColumns(?int $bestOf): array
    {
        if ($bestOf === null || $bestOf < 1) {
            return [1, 2, 3];
        }

        return range(1, $bestOf);
    }

    public static function orientation(?int $bestOf): string
    {
        return ($bestOf ?? 0) >= 5 ? 'landscape' : 'portrait';
    }

    /**
     * @param  list<PrintGroupSheetData|array{best_of?: int|null}|null>  $sheets
     */
    public static function globalBestOf(array $sheets): int
    {
        $values = [];

        foreach ($sheets as $sheet) {
            $bestOf = $sheet instanceof PrintGroupSheetData
                ? $sheet->bestOf
                : (is_array($sheet) ? ($sheet['best_of'] ?? null) : null);

            if (is_int($bestOf) && $bestOf > 0) {
                $values[] = $bestOf;
            }
        }

        return $values === [] ? 3 : max($values);
    }

    /**
     * @param  list<PrintGroupSheetData|array{best_of?: int|null}|null>  $sheets
     */
    public static function orientationFromSheets(array $sheets): string
    {
        return self::orientation(self::globalBestOf($sheets));
    }

    public static function competitionTypeLabel(?string $type): string
    {
        return match ($type) {
            'doubles' => 'Dobles',
            'singles' => 'Singles',
            'team' => 'Equipos',
            default => $type ?: '—',
        };
    }

    public static function roundLabel(?int $groupRound): string
    {
        if ($groupRound === null) {
            return '—';
        }

        return 'R'.$groupRound;
    }

    public static function displayName(?string $displayName): string
    {
        if ($displayName === null || $displayName === '') {
            return '—';
        }

        return str_replace(' / ', " /\n", $displayName);
    }

    public static function wantsDownload(?Request $request = null): bool
    {
        $request ??= request();
        $value = $request->query('download');

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (! is_string($value)) {
            return false;
        }

        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }
}
