<?php

namespace App\Support\Audit;

use App\Models\Category;
use App\Models\Competition;

final class AuditCompetitionAttributes
{
    /**
     * @return list<string>
     */
    public static function auditableFields(): array
    {
        return [
            'name',
            'type',
            'format',
            'category_id',
            'points_per_set',
            'qualified_per_group',
            'group_stage_best_of',
            'knockout_stage_best_of',
            'semifinal_best_of',
            'final_best_of',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(Competition $competition): array
    {
        $competition->loadMissing('categoryModel:id,name');

        return self::fromCompetition($competition);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromCompetition(Competition $competition): array
    {
        $attributes = AuditChangeResolver::normalizeAttributes(
            $competition->only(self::auditableFields()),
        );

        $attributes['category_name'] = $competition->categoryModel?->name
            ?? self::categoryNameFromId($competition->category_id);

        return $attributes;
    }

    /**
     * @param  array{old: array<string, mixed>, new: array<string, mixed>}  $changes
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public static function enrichCategoryNames(array $changes): array
    {
        if (! array_key_exists('category_id', $changes['new'])) {
            return $changes;
        }

        $changes['old']['category_name'] = self::categoryNameFromId($changes['old']['category_id'] ?? null);
        $changes['new']['category_name'] = self::categoryNameFromId($changes['new']['category_id'] ?? null);

        return $changes;
    }

    private static function categoryNameFromId(mixed $categoryId): ?string
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        return Category::query()->whereKey((int) $categoryId)->value('name');
    }
}
