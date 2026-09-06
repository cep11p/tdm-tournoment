<?php

namespace App\Support\Print;

use Illuminate\Support\Str;

final class PrintPdfFilename
{
    public const MAX_BASENAME_LENGTH = 80;

    public static function group(string $groupName, int $groupId): string
    {
        return self::compose(
            name: $groupName,
            prefixed: static function (string $slug): string {
                return str_starts_with($slug, 'grupo') ? $slug : 'grupo-'.$slug;
            },
            fallback: 'grupo-'.$groupId,
        );
    }

    public static function competitionGroups(string $competitionName, int $competitionId): string
    {
        return self::compose(
            name: $competitionName,
            prefixed: static fn (string $slug): string => 'grupos-'.$slug,
            fallback: 'competencia-'.$competitionId.'-grupos',
        );
    }

    public static function teamTie(
        string $side1Name,
        ?string $side2Name,
        bool $isBye,
        int $teamTieId,
    ): string {
        $fallback = 'enfrentamiento-'.$teamTieId;
        $side1Slug = self::usableSlug($side1Name);
        $side2Slug = self::usableSlug((string) $side2Name);

        if ($isBye) {
            return self::compose(
                name: $side1Name,
                prefixed: static fn (string $slug): string => 'enfrentamiento-'.$slug.'-bye',
                fallback: $fallback,
            );
        }

        if ($side1Slug === '' || $side2Slug === '') {
            if ($side1Slug === '') {
                return $fallback.'.pdf';
            }

            return self::compose(
                name: $side1Name,
                prefixed: static fn (string $slug): string => 'enfrentamiento-'.$slug,
                fallback: $fallback,
            );
        }

        return self::compose(
            name: trim($side1Name).' vs '.trim((string) $side2Name),
            prefixed: static fn (string $slug): string => 'enfrentamiento-'.$slug,
            fallback: $fallback,
        );
    }

    private static function usableSlug(string $name): string
    {
        if (preg_match('/[\p{L}\p{N}]/u', $name) !== 1) {
            return '';
        }

        return Str::slug($name, '-', 'es');
    }

    /**
     * @param  callable(string): string  $prefixed
     */
    private static function compose(string $name, callable $prefixed, string $fallback): string
    {
        $slug = preg_match('/[\p{L}\p{N}]/u', $name) === 1
            ? Str::slug($name, '-', 'es')
            : '';
        $base = $slug === '' ? $fallback : $prefixed($slug);
        $base = str_replace(['/', '\\'], '', $base);
        $base = trim($base, '.-');

        if ($base === '') {
            $base = $fallback;
        }

        if (strlen($base) > self::MAX_BASENAME_LENGTH) {
            $base = rtrim(substr($base, 0, self::MAX_BASENAME_LENGTH), '-.');
        }

        if ($base === '') {
            $base = $fallback;
        }

        return $base.'.pdf';
    }
}
