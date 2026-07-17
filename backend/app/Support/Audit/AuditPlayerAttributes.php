<?php

namespace App\Support\Audit;

use App\Models\Category;
use App\Models\Club;
use App\Models\Player;

final class AuditPlayerAttributes
{
    /**
     * @return array<string, mixed>
     */
    public static function snapshot(Player $player): array
    {
        $player->loadMissing(['category:id,name', 'club:id,name']);

        return self::fromPlayer($player);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromPlayer(Player $player): array
    {
        return [
            'first_name' => $player->first_name,
            'last_name' => $player->last_name,
            'nickname' => $player->nickname,
            'category_id' => $player->category_id,
            'category_name' => $player->category?->name,
            'club_id' => $player->club_id,
            'club_name' => $player->club?->name,
            'active' => $player->active,
        ];
    }

    /**
     * @param  array{old: array<string, mixed>, new: array<string, mixed>}  $changes
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public static function enrichRelationNames(array $changes): array
    {
        foreach (['category_id', 'club_id'] as $field) {
            if (! array_key_exists($field, $changes['new'])) {
                continue;
            }

            $relation = $field === 'category_id' ? 'category_name' : 'club_name';
            $modelClass = $field === 'category_id' ? Category::class : Club::class;

            $changes['old'][$relation] = self::relationName(
                $modelClass,
                $changes['old'][$field] ?? null,
            );
            $changes['new'][$relation] = self::relationName(
                $modelClass,
                $changes['new'][$field] ?? null,
            );
        }

        return $changes;
    }

    private static function relationName(string $modelClass, mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        /** @var Category|Club|null $model */
        $model = $modelClass::query()->find((int) $id);

        return $model?->name;
    }
}
