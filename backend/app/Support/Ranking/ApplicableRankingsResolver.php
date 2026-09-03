<?php

namespace App\Support\Ranking;

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\Ranking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ApplicableRankingsResolver
{
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

        $tournamentDate = $competition->tournament?->start_date;

        return Ranking::query()
            ->active()
            ->where('competition_type', $type->value)
            ->where(function ($query) use ($competition): void {
                $query->whereNull('category_id');

                if ($competition->category_id !== null) {
                    $query->orWhere('category_id', $competition->category_id);
                }
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (Ranking $ranking): bool => $this->matchesDateWindow($ranking, $tournamentDate))
            ->values();
    }

    private function matchesDateWindow(Ranking $ranking, mixed $tournamentDate): bool
    {
        if ($ranking->starts_at === null && $ranking->ends_at === null) {
            return true;
        }

        if ($tournamentDate === null) {
            return false;
        }

        $date = $tournamentDate instanceof Carbon
            ? $tournamentDate->startOfDay()
            : Carbon::parse((string) $tournamentDate)->startOfDay();

        if ($ranking->starts_at !== null && $date->lt($ranking->starts_at->copy()->startOfDay())) {
            return false;
        }

        if ($ranking->ends_at !== null && $date->gt($ranking->ends_at->copy()->startOfDay())) {
            return false;
        }

        return true;
    }
}
