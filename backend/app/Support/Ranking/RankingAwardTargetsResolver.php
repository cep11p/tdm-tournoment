<?php

namespace App\Support\Ranking;

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\Ranking;
use Illuminate\Support\Collection;

final class RankingAwardTargetsResolver
{
    public function __construct(
        private readonly RankingScopeMatcher $scopeMatcher,
    ) {}

    /**
     * Rankings que deben reconstruirse para una competencia:
     * scope actual (type/category/date) y (activos o ya premiados).
     *
     * No consulta el ledger: el caller pasa `previouslyAwardedRankingIds`
     * capturados antes de borrar transactions.
     *
     * Team nunca es target en V1.
     *
     * @param  list<int>  $previouslyAwardedRankingIds
     * @return Collection<int, Ranking>
     */
    public function resolve(Competition $competition, array $previouslyAwardedRankingIds): Collection
    {
        $competition->loadMissing('tournament');

        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::tryFrom((string) $competition->type);

        if ($type === null || $type->isTeam()) {
            return new Collection;
        }

        $previouslyAwarded = array_fill_keys(
            array_map(static fn (mixed $id): int => (int) $id, $previouslyAwardedRankingIds),
            true,
        );

        return $this->scopeMatcher
            ->constrainQuery(Ranking::query(), $competition)
            ->with('rules')
            ->orderBy('id')
            ->get()
            ->filter(function (Ranking $ranking) use ($competition, $previouslyAwarded): bool {
                if (! $this->scopeMatcher->matches($ranking, $competition)) {
                    return false;
                }

                return (bool) $ranking->active || isset($previouslyAwarded[(int) $ranking->id]);
            })
            ->unique('id')
            ->sortBy('id')
            ->values();
    }
}
