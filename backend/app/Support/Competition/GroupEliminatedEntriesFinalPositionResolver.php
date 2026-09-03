<?php

namespace App\Support\Competition;

use App\Data\Competition\CompetitionFinalStandingData;
use App\Data\Competition\CompetitionStandingData;
use App\Enums\CompetitionFinalStandingSource;
use App\Models\Competition;
use App\Support\Group\GroupStandingsResolver;
use Illuminate\Validation\ValidationException;

final class GroupEliminatedEntriesFinalPositionResolver
{
    public function __construct(
        private readonly GroupStandingsResolver $groupStandingsResolver,
    ) {}

    /**
     * @param  array<int, true>  $bracketEntryIds
     * @return list<CompetitionFinalStandingData>
     */
    public function resolve(Competition $competition, array $bracketEntryIds): array
    {
        $eliminatedByOffset = [];

        foreach ($competition->groups()->orderBy('id')->get() as $group) {
            $standingsResult = $this->groupStandingsResolver->calculate($group);

            if ($standingsResult->isProvisional) {
                throw ValidationException::withMessages([
                    'competition' => ['No se puede consolidar la clasificación final porque la competencia no está finalizada.'],
                ]);
            }

            $offset = 0;

            /** @var CompetitionStandingData $standing */
            foreach ($standingsResult->standings as $standing) {
                $entryId = (int) $standing->competitionEntryId;

                if (isset($bracketEntryIds[$entryId])) {
                    continue;
                }

                $offset++;
                $eliminatedByOffset[$offset][] = $entryId;
            }
        }

        if ($eliminatedByOffset === []) {
            return [];
        }

        ksort($eliminatedByOffset);

        $cursor = count($bracketEntryIds) + 1;
        $placements = [];

        foreach ($eliminatedByOffset as $entryIds) {
            $count = count($entryIds);
            $position = $cursor;
            $positionRangeEnd = $cursor + $count - 1;

            foreach ($entryIds as $entryId) {
                $placements[] = new CompetitionFinalStandingData(
                    competitionEntryId: $entryId,
                    position: $position,
                    positionRangeEnd: $positionRangeEnd,
                    source: CompetitionFinalStandingSource::GroupStage,
                    displayNameSnapshot: '',
                );
            }

            $cursor = $positionRangeEnd + 1;
        }

        return $placements;
    }
}
