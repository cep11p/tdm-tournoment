<?php

namespace App\Support\Ranking;

use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\Ranking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class RankingScopeMatcher
{
    public function matches(Ranking $ranking, Competition $competition): bool
    {
        $competition->loadMissing('tournament');

        return $this->matchesType($ranking, $competition)
            && $this->matchesCategory($ranking, $competition)
            && $this->matchesDateWindow($ranking, $competition->tournament?->start_date);
    }

    /**
     * @param  Builder<Ranking>  $query
     * @return Builder<Ranking>
     */
    public function constrainQuery(Builder $query, Competition $competition): Builder
    {
        $type = $this->competitionType($competition);

        if ($type === null) {
            return $query->whereRaw('0 = 1');
        }

        return $query
            ->where('competition_type', $type->value)
            ->where(function (Builder $categoryQuery) use ($competition): void {
                $categoryQuery->whereNull('category_id');

                if ($competition->category_id !== null) {
                    $categoryQuery->orWhere('category_id', $competition->category_id);
                }
            });
    }

    public function matchesDateWindow(Ranking $ranking, mixed $tournamentDate): bool
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

    private function matchesType(Ranking $ranking, Competition $competition): bool
    {
        $rankingType = $this->rankingType($ranking);
        $competitionType = $this->competitionType($competition);

        return $rankingType !== null
            && $competitionType !== null
            && $rankingType === $competitionType;
    }

    private function matchesCategory(Ranking $ranking, Competition $competition): bool
    {
        if ($ranking->category_id === null) {
            return true;
        }

        return $competition->category_id !== null
            && (int) $ranking->category_id === (int) $competition->category_id;
    }

    private function rankingType(Ranking $ranking): ?CompetitionType
    {
        return $ranking->competition_type instanceof CompetitionType
            ? $ranking->competition_type
            : CompetitionType::tryFrom((string) $ranking->competition_type);
    }

    private function competitionType(Competition $competition): ?CompetitionType
    {
        return $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::tryFrom((string) $competition->type);
    }
}
