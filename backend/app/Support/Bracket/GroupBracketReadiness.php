<?php

namespace App\Support\Bracket;

use App\Data\Competition\CompetitionStandingData;
use App\Models\Competition;
use App\Models\Group;
use App\Support\Group\GroupStandingsCalculator;
use Illuminate\Support\Collection;

final class GroupBracketReadiness
{
    public function __construct(
        private readonly GroupStandingsCalculator $groupStandingsCalculator,
    ) {}

    public function requiresAttentionBeforeBracket(Competition $competition): bool
    {
        $qualifiersPerGroup = (int) $competition->qualified_per_group;

        foreach ($competition->groups()->get() as $group) {
            if ($this->groupRequiresAttentionBeforeBracket($group, $qualifiersPerGroup)) {
                return true;
            }
        }

        return false;
    }

    public function groupRequiresAttentionBeforeBracket(Group $group, int $qualifiersPerGroup): bool
    {
        $standingsResult = $this->groupStandingsCalculator->calculate($group);

        if ($standingsResult->staleManualTiebreaks !== []) {
            return true;
        }

        if (! $standingsResult->requiresManualTiebreak()) {
            return false;
        }

        $eligibleStandings = $standingsResult->standings
            ->filter(fn (CompetitionStandingData $standing): bool => $standing->eligibleForQualification)
            ->values();

        $availableQualifiers = min($qualifiersPerGroup, $eligibleStandings->count());

        return self::manualTieCrossesQualifierCutoff(
            standings: $eligibleStandings,
            manualTiebreakGroups: $standingsResult->manualTiebreakGroups,
            qualifierCutoff: $availableQualifiers,
        );
    }

    /**
     * @param  Collection<int, CompetitionStandingData>  $standings
     * @param  array<int, array{player_ids: array<int, int>, player_names: array<int, string>}>  $manualTiebreakGroups
     */
    public static function manualTieCrossesQualifierCutoff(
        Collection $standings,
        array $manualTiebreakGroups,
        int $qualifierCutoff,
    ): bool {
        if ($qualifierCutoff <= 0) {
            return false;
        }

        $positionByPlayerId = $standings
            ->values()
            ->mapWithKeys(fn (CompetitionStandingData $standing, int $index): array => [
                $standing->playerId => $index,
            ])
            ->all();

        foreach ($manualTiebreakGroups as $manualTiebreakGroup) {
            $positions = collect($manualTiebreakGroup['player_ids'] ?? [])
                ->map(fn (int $playerId): ?int => $positionByPlayerId[$playerId] ?? null)
                ->filter(fn (?int $position): bool => $position !== null)
                ->values();

            if ($positions->isEmpty()) {
                continue;
            }

            $minPosition = (int) $positions->min();
            $maxPosition = (int) $positions->max();

            if ($minPosition < $qualifierCutoff && $maxPosition >= $qualifierCutoff) {
                return true;
            }
        }

        return false;
    }
}
