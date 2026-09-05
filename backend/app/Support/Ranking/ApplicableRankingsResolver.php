<?php

namespace App\Support\Ranking;

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\Ranking;
use Illuminate\Support\Collection;

final class ApplicableRankingsResolver
{
    public function __construct(
        private readonly RankingScopeMatcher $scopeMatcher,
    ) {}

    /**
     * Rankings activos que aplican a la competencia por tipo, categoría y ventana de fechas.
     *
     * `season` es una etiqueta explícita; no se deriva de now() ni del año del torneo.
     * Si `starts_at`/`ends_at` están definidos, se compara contra `Tournament.start_date`.
     * Sin fechas, el ranking aplica por tipo/categoría únicamente.
     *
     * Team nunca aplica en V1.
     *
     * @return Collection<int, Ranking>
     */
    public function resolve(Competition $competition): Collection
    {
        $competition->loadMissing('tournament');

        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::tryFrom((string) $competition->type);

        if ($type === null || $type->isTeam()) {
            return new Collection;
        }

        return $this->scopeMatcher
            ->constrainQuery(Ranking::query()->active(), $competition)
            ->orderBy('id')
            ->get()
            ->filter(fn (Ranking $ranking): bool => $this->scopeMatcher->matches($ranking, $competition))
            ->values();
    }
}
