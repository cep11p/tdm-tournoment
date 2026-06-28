<?php

namespace App\Support\Bracket;

use App\Data\Competition\CompetitionStandingData;
use App\Data\Competition\GroupQualifierData;
use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\Group;
use App\Support\Group\GroupStandingsCalculator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class GroupQualifiersCollector
{
    public function __construct(
        private readonly GroupStandingsCalculator $groupStandingsCalculator,
    ) {}

    /**
     * @return Collection<int, GroupQualifierData>
     */
    public function collect(Competition $competition): Collection
    {
        $qualifiersPerGroup = (int) $competition->qualified_per_group;
        $qualifiers = collect();

        foreach ($competition->groups()->get() as $group) {
            $groupQualifiers = $this->qualifiersFromGroup($group, $qualifiersPerGroup);
            $qualifiers = $qualifiers->concat($groupQualifiers);
        }

        return $qualifiers->values();
    }

    /**
     * @return Collection<int, GroupQualifierData>
     */
    private function qualifiersFromGroup(Group $group, int $qualifiersPerGroup): Collection
    {
        $groupPlayers = $group->groupPlayers()
            ->with('player:id,first_name,last_name')
            ->get();

        if ($groupPlayers->count() < 2) {
            throw ValidationException::withMessages([
                'group' => [sprintf('El grupo "%s" necesita al menos 2 jugadores.', $group->name)],
            ]);
        }

        if (! $group->games()->exists()) {
            throw ValidationException::withMessages([
                'group' => [sprintf('El grupo "%s" no tiene partidos generados.', $group->name)],
            ]);
        }

        $hasUnfinishedGames = $group->games()
            ->where('status', '!=', GameStatus::Finished)
            ->exists();

        if ($hasUnfinishedGames) {
            throw ValidationException::withMessages([
                'group' => [sprintf('El grupo "%s" todavía tiene partidos sin finalizar.', $group->name)],
            ]);
        }

        $standingsResult = $this->groupStandingsCalculator->calculate($group);
        $eligibleStandings = $standingsResult->standings
            ->filter(fn (CompetitionStandingData $standing): bool => $standing->eligibleForQualification)
            ->values();

        $availableQualifiers = min($qualifiersPerGroup, $eligibleStandings->count());
        $groupQualifiers = $eligibleStandings->take($availableQualifiers);

        if (
            $standingsResult->requiresManualTiebreak()
            && $this->manualTieCrossesQualifierCutoff(
                standings: $eligibleStandings,
                manualTiebreakGroups: $standingsResult->manualTiebreakGroups,
                qualifierCutoff: $availableQualifiers,
            )
        ) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    sprintf(
                        'El grupo "%s" requiere desempate manual para definir la clasificación.',
                        $group->name
                    ),
                ],
            ]);
        }

        return $groupQualifiers->values()->map(
            fn (CompetitionStandingData $standing, int $index): GroupQualifierData => new GroupQualifierData(
                playerId: $standing->playerId,
                playerName: $standing->playerName,
                groupId: (int) $group->id,
                groupName: (string) $group->name,
                groupPosition: $index + 1,
                won: $standing->won,
                lost: $standing->lost,
            ),
        );
    }

    /**
     * @param  Collection<int, CompetitionStandingData>  $standings
     * @param  array<int, array{player_ids: array<int, int>, player_names: array<int, string>}>  $manualTiebreakGroups
     */
    private function manualTieCrossesQualifierCutoff(
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
