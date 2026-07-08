<?php

namespace App\Support\Bracket;

use App\Data\Bracket\BracketDrawMatchData;
use App\Data\Bracket\GroupKnockoutDrawResult;
use App\Data\Competition\GroupQualifierData;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class GroupKnockoutDrawBuilder
{
    /**
     * @param  Collection<int, GroupQualifierData>  $qualifiers
     * @return array<int, int>
     */
    public function build(Collection $qualifiers, int $qualifiedPerGroup): array
    {
        if ($qualifiedPerGroup !== 2) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    'El draw consciente de grupos para posiciones usa buildDraw() con qualified_per_group = 3.',
                ],
            ]);
        }

        $groups = $this->orderedGroups($qualifiers);
        $groupCount = $groups->count();

        if (! $this->isPowerOfTwo($groupCount)) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    sprintf(
                        'El cuadro eliminatorio requiere una cantidad de grupos potencia de 2 (actual: %d).',
                        $groupCount,
                    ),
                ],
            ]);
        }

        $firsts = [];
        $seconds = [];

        foreach ($groups as $groupQualifiers) {
            $byPosition = $this->qualifiersByPosition($groupQualifiers);

            $firsts[] = $this->requirePosition($byPosition, 1, $groupQualifiers);
            $seconds[] = $this->requirePosition($byPosition, 2, $groupQualifiers);
        }

        if (count($firsts) !== count($seconds)) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    'La cantidad de primeros y segundos clasificados debe ser la misma.',
                ],
            ]);
        }

        $total = $groupCount * 2;

        if (! $this->isPowerOfTwo($total)) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    sprintf(
                        'El cuadro eliminatorio requiere una cantidad total de clasificados potencia de 2 (actual: %d).',
                        $total,
                    ),
                ],
            ]);
        }

        $playerIds = array_fill(0, $total, 0);

        for ($index = 0; $index < $groupCount; $index++) {
            $playerIds[$index] = $firsts[$index]->playerId;
            $playerIds[$total - 1 - $index] = $seconds[$groupCount - 1 - $index]->playerId;
        }

        return $playerIds;
    }

    /**
     * @param  Collection<int, GroupQualifierData>  $qualifiers
     */
    public function canBuildPlayInDraw(Collection $qualifiers, int $qualifiedPerGroup = 3): bool
    {
        if ($qualifiedPerGroup !== 3) {
            return false;
        }

        $groups = $this->orderedGroups($qualifiers);
        $groupCount = $groups->count();

        if ($groupCount < 4 || ! $this->isPowerOfTwo($groupCount)) {
            return false;
        }

        $firsts = [];
        $seconds = [];
        $thirds = [];

        foreach ($groups as $groupQualifiers) {
            $byPosition = $this->qualifiersByPosition($groupQualifiers);

            if (! isset($byPosition[1], $byPosition[2], $byPosition[3])) {
                return false;
            }

            $firsts[] = $byPosition[1];
            $seconds[] = $byPosition[2];
            $thirds[] = $byPosition[3];
        }

        if (count($firsts) !== count($seconds) || count($firsts) !== count($thirds)) {
            return false;
        }

        $playIns = $this->tryBuildPlayInCandidates($seconds, $thirds, $groupCount);

        if ($playIns === null) {
            return false;
        }

        return $this->assignCompatiblePlayIns($firsts, $playIns) !== null;
    }

    /**
     * @param  Collection<int, GroupQualifierData>  $qualifiers
     * @return array<int, int>
     */
    public function buildDirectPlayerIds(Collection $qualifiers, int $qualifiedPerGroup): array
    {
        $groups = $this->orderedGroups($qualifiers);
        $playerIds = [];

        for ($position = 1; $position <= $qualifiedPerGroup; $position++) {
            foreach ($groups as $groupQualifiers) {
                $byPosition = $this->qualifiersByPosition($groupQualifiers);

                if (! isset($byPosition[$position])) {
                    throw ValidationException::withMessages([
                        'qualified_per_group' => [
                            sprintf(
                                'Falta el %d° clasificado del grupo "%s".',
                                $position,
                                $groupQualifiers->first()->groupName,
                            ),
                        ],
                    ]);
                }

                $playerIds[] = $byPosition[$position]->playerId;
            }
        }

        return $playerIds;
    }

    /**
     * @param  Collection<int, GroupQualifierData>  $qualifiers
     */
    public function buildDraw(Collection $qualifiers, int $qualifiedPerGroup): GroupKnockoutDrawResult
    {
        if ($qualifiedPerGroup !== 3) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    'El draw consciente de grupos con play-in solo admite 3 clasificados por grupo.',
                ],
            ]);
        }

        $groups = $this->orderedGroups($qualifiers);
        $groupCount = $groups->count();

        if ($groupCount < 4 || ! $this->isPowerOfTwo($groupCount)) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    sprintf(
                        'El draw con play-in requiere 4, 8 o 16 grupos (actual: %d).',
                        $groupCount,
                    ),
                ],
            ]);
        }

        $firsts = [];
        $seconds = [];
        $thirds = [];

        foreach ($groups as $groupQualifiers) {
            $byPosition = $this->qualifiersByPosition($groupQualifiers);

            $firsts[] = $this->requirePosition($byPosition, 1, $groupQualifiers);
            $seconds[] = $this->requirePosition($byPosition, 2, $groupQualifiers);
            $thirds[] = $this->requirePosition($byPosition, 3, $groupQualifiers);
        }

        if (count($firsts) !== count($seconds) || count($firsts) !== count($thirds)) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    'La cantidad de primeros, segundos y terceros clasificados debe ser la misma.',
                ],
            ]);
        }

        $playIns = $this->buildPlayInCandidates($seconds, $thirds, $groupCount);
        $assignment = $this->assignCompatiblePlayIns($firsts, $playIns);

        if ($assignment === null) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    'No se pudo construir un draw válido sin cruces del mismo grupo en la ronda posterior al play-in.',
                ],
            ]);
        }

        $qualifierCount = $groupCount * 3;
        $bracketSize = BracketSupport::nextPowerOfTwo($qualifierCount);
        $byesCount = $groupCount;
        $matches = [];

        foreach ($assignment as $pairIndex => ['first' => $first, 'play_in' => $playIn]) {
            $byeMatchNumber = ($pairIndex * 2) + 1;
            $playInMatchNumber = $byeMatchNumber + 1;

            $matches[] = new BracketDrawMatchData(
                bracketMatch: $byeMatchNumber,
                player1Id: $first->playerId,
                player2Id: null,
                isBye: true,
            );

            $matches[] = new BracketDrawMatchData(
                bracketMatch: $playInMatchNumber,
                player1Id: $playIn['second']->playerId,
                player2Id: $playIn['third']->playerId,
                isBye: false,
            );
        }

        return new GroupKnockoutDrawResult(
            bracketSize: $bracketSize,
            byesCount: $byesCount,
            firstRoundLabel: BracketSupport::PLAY_IN_ROUND_LABEL,
            matches: $matches,
        );
    }

    /**
     * @param  Collection<int, GroupQualifierData>  $qualifiers
     * @return Collection<int, Collection<int, GroupQualifierData>>
     */
    private function orderedGroups(Collection $qualifiers): Collection
    {
        return $qualifiers
            ->groupBy(fn (GroupQualifierData $qualifier): int => $qualifier->groupId)
            ->sortBy(
                fn (Collection $groupQualifiers): string => $groupQualifiers->first()->groupName,
            )
            ->values();
    }

    /**
     * @param  Collection<int, GroupQualifierData>  $groupQualifiers
     * @return Collection<int, GroupQualifierData>
     */
    private function qualifiersByPosition(Collection $groupQualifiers): Collection
    {
        return $groupQualifiers->keyBy(
            fn (GroupQualifierData $qualifier): int => $qualifier->groupPosition,
        );
    }

    /**
     * @param  Collection<int, GroupQualifierData>  $byPosition
     */
    private function requirePosition(
        Collection $byPosition,
        int $position,
        Collection $groupQualifiers,
    ): GroupQualifierData {
        if (! isset($byPosition[$position])) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    sprintf(
                        'Falta el %d° clasificado del grupo "%s".',
                        $position,
                        $groupQualifiers->first()->groupName,
                    ),
                ],
            ]);
        }

        return $byPosition[$position];
    }

    /**
     * @param  array<int, GroupQualifierData>  $seconds
     * @param  array<int, GroupQualifierData>  $thirds
     * @return list<array{second: GroupQualifierData, third: GroupQualifierData}>
     */
    private function buildPlayInCandidates(array $seconds, array $thirds, int $groupCount): array
    {
        $playIns = $this->tryBuildPlayInCandidates($seconds, $thirds, $groupCount);

        if ($playIns === null) {
            $second = $seconds[0];
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    sprintf(
                        'El play-in no puede enfrentar jugadores del mismo grupo ("%s").',
                        $second->groupName,
                    ),
                ],
            ]);
        }

        return $playIns;
    }

    /**
     * @param  array<int, GroupQualifierData>  $seconds
     * @param  array<int, GroupQualifierData>  $thirds
     * @return list<array{second: GroupQualifierData, third: GroupQualifierData}>|null
     */
    private function tryBuildPlayInCandidates(array $seconds, array $thirds, int $groupCount): ?array
    {
        $playIns = [];

        for ($index = 0; $index < $groupCount; $index++) {
            $second = $seconds[$index];
            $third = $thirds[$groupCount - 1 - $index];

            if ($second->groupId === $third->groupId) {
                return null;
            }

            $playIns[] = [
                'second' => $second,
                'third' => $third,
            ];
        }

        return $playIns;
    }

    /**
     * @param  array<int, GroupQualifierData>  $firsts
     * @param  list<array{second: GroupQualifierData, third: GroupQualifierData}>  $playIns
     * @return list<array{first: GroupQualifierData, play_in: array{second: GroupQualifierData, third: GroupQualifierData}}>|null
     */
    private function assignCompatiblePlayIns(array $firsts, array $playIns): ?array
    {
        $groupCount = count($firsts);
        $usedPlayIns = array_fill(0, $groupCount, false);
        $assignment = [];

        if ($this->backtrackFirstToPlayInAssignment($firsts, $playIns, 0, $usedPlayIns, $assignment)) {
            return $assignment;
        }

        return null;
    }

    /**
     * @param  array<int, GroupQualifierData>  $firsts
     * @param  list<array{second: GroupQualifierData, third: GroupQualifierData}>  $playIns
     * @param  array<int, bool>  $usedPlayIns
     * @param  list<array{first: GroupQualifierData, play_in: array{second: GroupQualifierData, third: GroupQualifierData}}>  $assignment
     */
    private function backtrackFirstToPlayInAssignment(
        array $firsts,
        array $playIns,
        int $firstIndex,
        array &$usedPlayIns,
        array &$assignment,
    ): bool {
        if ($firstIndex >= count($firsts)) {
            return true;
        }

        $first = $firsts[$firstIndex];

        foreach ($playIns as $playInIndex => $playIn) {
            if ($usedPlayIns[$playInIndex]) {
                continue;
            }

            if (! $this->isCompatibleFirstWithPlayIn($first, $playIn)) {
                continue;
            }

            $usedPlayIns[$playInIndex] = true;
            $assignment[] = [
                'first' => $first,
                'play_in' => $playIn,
            ];

            if ($this->backtrackFirstToPlayInAssignment($firsts, $playIns, $firstIndex + 1, $usedPlayIns, $assignment)) {
                return true;
            }

            array_pop($assignment);
            $usedPlayIns[$playInIndex] = false;
        }

        return false;
    }

    /**
     * @param  array{second: GroupQualifierData, third: GroupQualifierData}  $playIn
     */
    private function isCompatibleFirstWithPlayIn(GroupQualifierData $first, array $playIn): bool
    {
        return $first->groupId !== $playIn['second']->groupId
            && $first->groupId !== $playIn['third']->groupId;
    }

    private function isPowerOfTwo(int $value): bool
    {
        return $value > 0 && ($value & ($value - 1)) === 0;
    }
}
