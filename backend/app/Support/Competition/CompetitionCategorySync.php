<?php

namespace App\Support\Competition;

use App\Models\Category;
use App\Models\Competition;

final class CompetitionCategorySync
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function apply(array $payload, ?Competition $competition = null): array
    {
        if (! array_key_exists('category_id', $payload)) {
            return $payload;
        }

        $categoryId = $payload['category_id'];

        if ($categoryId === null || $categoryId === '') {
            $payload['category_id'] = null;

            return $payload;
        }

        $category = Category::query()->find((int) $categoryId);

        if ($category !== null) {
            $payload['category'] = $category->slug;
        }

        return $payload;
    }

    public static function competitionCategorySlug(Competition $competition): ?string
    {
        if ($competition->relationLoaded('categoryModel') && $competition->categoryModel !== null) {
            return $competition->categoryModel->slug;
        }

        if ($competition->category_id !== null) {
            $category = Category::query()->find($competition->category_id);

            return $category?->slug;
        }

        $legacy = mb_strtolower(trim((string) $competition->category));

        return $legacy !== '' ? $legacy : null;
    }
}
