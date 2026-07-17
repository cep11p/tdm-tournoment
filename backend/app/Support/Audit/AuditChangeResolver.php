<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;

final class AuditChangeResolver
{
    /**
     * @param  list<string>  $allowedFields
     * @return array{old: array<string, mixed>, new: array<string, mixed>}|null
     */
    public static function resolve(Model $model, array $allowedFields): ?array
    {
        $dirty = array_intersect_key(
            $model->getDirty(),
            array_flip($allowedFields),
        );

        if ($dirty === []) {
            return null;
        }

        $old = [];
        $new = [];

        foreach (array_keys($dirty) as $field) {
            $old[$field] = AuditValueNormalizer::normalize($model->getOriginal($field));
            $new[$field] = AuditValueNormalizer::normalize($model->getAttribute($field));
        }

        return [
            'old' => $old,
            'new' => $new,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizeAttributes(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $key => $value) {
            $normalized[$key] = AuditValueNormalizer::normalize($value);
        }

        return $normalized;
    }
}
