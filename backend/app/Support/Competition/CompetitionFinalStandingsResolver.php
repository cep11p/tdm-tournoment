<?php

namespace App\Support\Competition;

use App\Data\Competition\CompetitionFinalStandingData;
use App\Enums\ThirdPlaceMode;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use Illuminate\Validation\ValidationException;

final class CompetitionFinalStandingsResolver
{
    public function __construct(
        private readonly BracketFinalPositionsResolver $bracketFinalPositionsResolver,
        private readonly GroupEliminatedEntriesFinalPositionResolver $groupEliminatedEntriesFinalPositionResolver,
    ) {}

    /**
     * @return list<CompetitionFinalStandingData>
     */
    public function resolve(Competition $competition): array
    {
        $competition->loadMissing(['entries', 'brackets.games', 'brackets.teamTies', 'groups']);

        $bracket = $competition->brackets->first();

        if ($bracket === null) {
            throw ValidationException::withMessages([
                'competition' => ['No se puede consolidar la clasificación final porque la competencia no está finalizada.'],
            ]);
        }

        $thirdPlaceMode = $competition->third_place_mode instanceof ThirdPlaceMode
            ? $competition->third_place_mode
            : ThirdPlaceMode::from((string) $competition->third_place_mode);

        $snapshots = $competition->isTeam()
            ? CompetitionFinalMatchSnapshot::fromTeamTies($bracket->teamTies)
            : CompetitionFinalMatchSnapshot::fromGames($bracket->games);

        $bracketPlacements = $this->bracketFinalPositionsResolver->resolve(
            bracketSize: (int) $bracket->bracket_size,
            thirdPlaceMode: $thirdPlaceMode,
            snapshots: $snapshots,
        );

        $bracketEntryIds = [];

        foreach ($bracketPlacements as $placement) {
            $bracketEntryIds[$placement->competitionEntryId] = true;
        }

        $groupPlacements = $this->groupEliminatedEntriesFinalPositionResolver->resolve(
            $competition,
            $bracketEntryIds,
        );

        $placements = [...$bracketPlacements, ...$groupPlacements];
        $entries = $competition->entries;
        $entryIds = $entries->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $this->assertCoverage($entryIds, $placements);

        $entriesById = $entries->keyBy(fn (CompetitionEntry $entry): int => (int) $entry->id);
        $resolved = [];

        foreach ($placements as $placement) {
            $entry = $entriesById->get($placement->competitionEntryId);

            if (! $entry instanceof CompetitionEntry) {
                throw ValidationException::withMessages([
                    'competition' => ['La clasificación final contiene una participación que no pertenece a la competencia.'],
                ]);
            }

            $resolved[] = new CompetitionFinalStandingData(
                competitionEntryId: $placement->competitionEntryId,
                position: $placement->position,
                positionRangeEnd: $placement->positionRangeEnd,
                source: $placement->source,
                displayNameSnapshot: CompetitionEntryDisplayName::for($entry),
            );
        }

        usort(
            $resolved,
            fn (CompetitionFinalStandingData $left, CompetitionFinalStandingData $right): int => [
                $left->position,
                $left->competitionEntryId,
            ] <=> [
                $right->position,
                $right->competitionEntryId,
            ],
        );

        return $resolved;
    }

    /**
     * @param  list<int>  $entryIds
     * @param  list<CompetitionFinalStandingData>  $placements
     */
    private function assertCoverage(array $entryIds, array $placements): void
    {
        $placementEntryIds = array_map(
            fn (CompetitionFinalStandingData $placement): int => $placement->competitionEntryId,
            $placements,
        );

        if (count($placementEntryIds) !== count(array_unique($placementEntryIds))) {
            throw ValidationException::withMessages([
                'competition' => ['La clasificación final contiene participaciones duplicadas.'],
            ]);
        }

        sort($entryIds);
        $sortedPlacementIds = $placementEntryIds;
        sort($sortedPlacementIds);

        if ($entryIds !== $sortedPlacementIds) {
            throw ValidationException::withMessages([
                'competition' => ['La clasificación final no cubre todas las participaciones de la competencia.'],
            ]);
        }
    }
}
