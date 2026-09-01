<?php

namespace App\Support\Group;

use App\Models\Game;
use App\Models\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class GroupFixtureOrder
{
    /**
     * Sporting fixture order: group_round ASC NULLS LAST, group_match ASC, id ASC.
     *
     * `ORDER BY group_round IS NULL` puts non-null rounds first on SQLite and MySQL
     * (false/0 before true/1), matching NULLS LAST without engine-specific syntax.
     *
     * @param  Builder<Game>  $query
     * @return Builder<Game>
     */
    public static function apply(Builder $query): Builder
    {
        return $query
            ->orderByRaw('group_round IS NULL')
            ->orderBy('group_round')
            ->orderBy('group_match')
            ->orderBy('id');
    }

    /**
     * @param  list<string>  $with
     * @return Collection<int, Game>
     */
    public static function games(Group $group, array $with = []): Collection
    {
        $query = $group->games()
            ->orderByRaw('group_round IS NULL')
            ->orderBy('group_round')
            ->orderBy('group_match')
            ->orderBy('id');

        if ($with !== []) {
            $query->with($with);
        }

        return $query->get();
    }
}
