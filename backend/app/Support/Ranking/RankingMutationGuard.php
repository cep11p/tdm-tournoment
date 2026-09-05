<?php

namespace App\Support\Ranking;

use App\Enums\CompetitionType;
use App\Models\Ranking;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class RankingMutationGuard
{
    public const STRUCTURAL_LOCKED_MESSAGE = 'La modalidad, categoría, temporada y vigencia están congeladas porque este ranking ya otorgó puntos.';

    public const ACTIVE_WITHOUT_RULES_MESSAGE = 'El ranking debe tener al menos una regla de puntuación activa antes de activarse.';

    /**
     * @var list<string>
     */
    public const STRUCTURAL_FIELDS = [
        'competition_type',
        'category_id',
        'season',
        'starts_at',
        'ends_at',
    ];

    public static function hasHistory(Ranking $ranking): bool
    {
        return $ranking->transactions()->exists();
    }

    public static function assertStructuralMutable(Ranking $ranking): void
    {
        if (! self::hasHistory($ranking)) {
            return;
        }

        if (! self::hasStructuralChanges($ranking)) {
            return;
        }

        throw ValidationException::withMessages([
            'ranking' => [self::STRUCTURAL_LOCKED_MESSAGE],
        ]);
    }

    public static function assertCanActivate(Ranking $ranking): void
    {
        if ($ranking->rules()->where('active', true)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'active' => [self::ACTIVE_WITHOUT_RULES_MESSAGE],
        ]);
    }

    public static function hasStructuralChanges(Ranking $ranking): bool
    {
        foreach (self::STRUCTURAL_FIELDS as $field) {
            $original = self::normalizeStructural($field, $ranking->getOriginal($field));
            $current = self::normalizeStructural($field, $ranking->getAttribute($field));

            if ($original !== $current) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeStructural(string $field, mixed $value): mixed
    {
        if ($field === 'competition_type') {
            if ($value instanceof CompetitionType) {
                return $value->value;
            }

            return $value === null || $value === '' ? null : (string) $value;
        }

        if ($field === 'category_id') {
            return $value === null || $value === '' ? null : (int) $value;
        }

        if ($field === 'season') {
            return $value === null ? null : (string) $value;
        }

        if (in_array($field, ['starts_at', 'ends_at'], true)) {
            if ($value === null || $value === '') {
                return null;
            }

            if ($value instanceof CarbonInterface) {
                return $value->toDateString();
            }

            return Carbon::parse((string) $value)->toDateString();
        }

        return $value;
    }
}
