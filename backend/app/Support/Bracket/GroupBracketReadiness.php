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
            pendingManualTieEntryGroups: $standingsResult->pendingManualTieEntryGroups,
            qualifierCutoff: $availableQualifiers,
        );
    }

    /**
     * @param  Collection<int, CompetitionStandingData>  $standings
     * @param  array<int, array<int, int>>  $pendingManualTieEntryGroups
     */
    public static function manualTieCrossesQualifierCutoff(
        Collection $standings,
        array $pendingManualTieEntryGroups,
        int $qualifierCutoff,
    ): bool {
        if ($qualifierCutoff <= 0) {
            return false;
        }

        $positionByEntryId = $standings
            ->values()
            ->mapWithKeys(fn (CompetitionStandingData $standing, int $index): array => [
                (int) $standing->competitionEntryId => $index,
            ])
            ->all();

        foreach ($pendingManualTieEntryGroups as $entryIds) {
            $positions = collect($entryIds)
                ->map(fn (int $entryId): ?int => $positionByEntryId[$entryId] ?? null)
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
