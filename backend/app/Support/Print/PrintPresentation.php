<?php

namespace App\Support\Print;

use App\Data\Group\PrintGroupSheetData;
use App\Data\TeamTie\PrintTeamTieData;
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

    public static function teamTieByeLabel(): string
    {
        return 'Pase directo';
    }

    public static function teamTieMissingLineupLabel(): string
    {
        return 'Por definir';
    }

    /**
     * @param  array{players?: list<array{id?: int|null, name?: string}>}|null  $side
     */
    public static function teamTieLineupLabel(?array $side): string
    {
        $players = is_array($side) && is_array($side['players'] ?? null) ? $side['players'] : [];
        $names = [];

        foreach ($players as $player) {
            $name = trim((string) ($player['name'] ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names === [] ? self::teamTieMissingLineupLabel() : implode(' / ', $names);
    }

    /**
     * @param  array{status?: string, official?: bool}|null  $rubber
     */
    public static function teamTieRubberStatusLabel(?array $rubber): string
    {
        $status = (string) ($rubber['status'] ?? 'pending');
        $official = (bool) ($rubber['official'] ?? false);

        if ($status === 'not_needed') {
            return 'No necesario';
        }

        if ($status === 'finished' && $official === false) {
            return 'No oficial';
        }

        if ($status === 'finished' && $official === true) {
            return '';
        }

        return match ($status) {
            'in_progress' => 'En juego',
            default => 'Pendiente',
        };
    }

    /**
     * @param  PrintTeamTieData|array{
     *     team_tie?: array{is_bye?: bool, status?: string},
     *     score?: array{side1?: int, side2?: int}
     * }  $sheet
     */
    public static function teamTieShouldShowScore(PrintTeamTieData|array $sheet): bool
    {
        if ($sheet instanceof PrintTeamTieData) {
            $isBye = (bool) ($sheet->teamTie['is_bye'] ?? false);
            $status = (string) ($sheet->teamTie['status'] ?? '');
            $side1 = (int) ($sheet->score['side1'] ?? 0);
            $side2 = (int) ($sheet->score['side2'] ?? 0);
        } else {
            $isBye = (bool) ($sheet['team_tie']['is_bye'] ?? false);
            $status = (string) ($sheet['team_tie']['status'] ?? '');
            $side1 = (int) ($sheet['score']['side1'] ?? 0);
            $side2 = (int) ($sheet['score']['side2'] ?? 0);
        }

        if ($isBye) {
            return false;
        }

        return $status === 'in_progress' || $status === 'finished' || $side1 > 0 || $side2 > 0;
    }

    public static function teamTieMatchupLabel(PrintTeamTieData $sheet): string
    {
        $side1 = self::teamTieSideName($sheet->side1, 'Equipo 1');
        $isBye = (bool) ($sheet->teamTie['is_bye'] ?? false);
        $side2 = self::teamTieSideName($sheet->side2, '');

        if ($isBye || $side2 === '') {
            return $side1;
        }

        return $side1.' vs '.$side2;
    }

    /**
     * @param  array{display_name?: string}|null  $side
     */
    public static function teamTieSideName(?array $side, string $fallback): string
    {
        $name = trim((string) (($side ?? [])['display_name'] ?? ''));

        return $name !== '' ? $name : $fallback;
    }
}
