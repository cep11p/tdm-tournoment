<?php

namespace App\Support\Ranking;

use App\Data\Ranking\RankingPointsMatch;
use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\CompetitionFinalStanding;
use App\Models\Ranking;
use App\Models\RankingRule;

final class RankingPointsResolver
{
    /**
     * @param  iterable<RankingRule>  $rules
     */
    public function resolve(
        Ranking $ranking,
        CompetitionFinalStanding $standing,
        iterable $rules,
    ): RankingPointsMatch {
        $standing->loadMissing('competition');

        if (! $this->rankingApplies($ranking, $standing->competition)) {
            return new RankingPointsMatch(null, 0);
        }

        $matches = [];

        foreach ($rules as $rule) {
            if (! $rule instanceof RankingRule) {
                continue;
            }

            if (! $this->ruleMatches($rule, $standing)) {
                continue;
            }

            $matches[] = $rule;
        }

        if ($matches === []) {
            return new RankingPointsMatch(null, 0);
        }

        usort(
            $matches,
            fn (RankingRule $left, RankingRule $right): int => [
                -1 * (int) $left->priority,
                (int) $left->id,
            ] <=> [
                -1 * (int) $right->priority,
                (int) $right->id,
            ],
        );

        $winner = $matches[0];

        return new RankingPointsMatch($winner, (int) $winner->points);
    }

    private function rankingApplies(Ranking $ranking, ?Competition $competition): bool
    {
        if ($competition === null || ! $ranking->active) {
            return false;
        }

        $rankingType = $ranking->competition_type instanceof CompetitionType
            ? $ranking->competition_type
            : CompetitionType::tryFrom((string) $ranking->competition_type);

        $competitionType = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::tryFrom((string) $competition->type);

        if ($rankingType === null || $competitionType === null || $rankingType !== $competitionType) {
            return false;
        }

        if ($rankingType->isTeam() || $competitionType->isTeam()) {
            return false;
        }

        if ($ranking->category_id === null) {
            return true;
        }

        return (int) $ranking->category_id === (int) $competition->category_id;
    }

    private function ruleMatches(RankingRule $rule, CompetitionFinalStanding $standing): bool
    {
        if (! $rule->active) {
            return false;
        }

        $ruleSource = $rule->source instanceof CompetitionFinalStandingSource
            ? $rule->source
            : CompetitionFinalStandingSource::tryFrom((string) $rule->source);

        $standingSource = $standing->source instanceof CompetitionFinalStandingSource
            ? $standing->source
            : CompetitionFinalStandingSource::tryFrom((string) $standing->source);

        if ($ruleSource === null || $standingSource === null || $ruleSource !== $standingSource) {
            return false;
        }

        if ($rule->position === null) {
            return true;
        }

        return (int) $rule->position === (int) $standing->position;
    }
}
