<?php

namespace App\Support\Group\Concerns;

use App\Support\Competition\GroupEntryIndex;

trait RanksEntryTieGroups
{
    /**
     * @param  array<int, int>  $left
     * @param  array<int, int>  $right
     */
    protected function entrySetsMatch(array $left, array $right): bool
    {
        $leftSorted = array_map('intval', $left);
        $rightSorted = array_map('intval', $right);
        sort($leftSorted);
        sort($rightSorted);

        return $leftSorted === $rightSorted;
    }

    /**
     * @param  array<int, int>  $orderedIds
     * @param  array<int, int>  $blockEntryIds
     * @param  array<int, int>  $manualOrder
     * @return array<int, int>
     */
    protected function replaceContiguousBlock(array $orderedIds, array $blockEntryIds, array $manualOrder): array
    {
        $blockIndex = $this->findContiguousBlockIndex($orderedIds, $blockEntryIds);

        if ($blockIndex === null) {
            return $orderedIds;
        }

        $blockLength = count($blockEntryIds);

        return [
            ...array_slice($orderedIds, 0, $blockIndex),
            ...$manualOrder,
            ...array_slice($orderedIds, $blockIndex + $blockLength),
        ];
    }

    /**
     * @param  array<int, int>  $orderedIds
     * @param  array<int, int>  $entryIds
     */
    protected function findContiguousBlockIndex(array $orderedIds, array $entryIds): ?int
    {
        $blockLength = count($entryIds);

        if ($blockLength === 0 || count($orderedIds) < $blockLength) {
            return null;
        }

        for ($index = 0; $index <= count($orderedIds) - $blockLength; $index++) {
            $window = array_slice($orderedIds, $index, $blockLength);

            if ($this->entrySetsMatch($window, $entryIds)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $entryIds
     * @param  array<int, array<string, int>>  $miniStats
     * @param  array<int, string>  $criteria
     * @return array{
     *     ordered_entry_ids: array<int, int>,
     *     manual_groups: array<int, array<int, int>>
     * }
     */
    protected function rankByMiniCriteria(
        array $entryIds,
        array $miniStats,
        GroupEntryIndex $index,
        array $criteria,
        int $currentCriterion,
    ): array {
        if (count($entryIds) <= 1) {
            return [
                'ordered_entry_ids' => $entryIds,
                'manual_groups' => [],
            ];
        }

        $criterion = $criteria[$currentCriterion] ?? null;

        if ($criterion === null) {
            $orderedByName = $this->sortEntriesByName($entryIds, $index);

            return [
                'ordered_entry_ids' => $orderedByName,
                'manual_groups' => [$orderedByName],
            ];
        }

        $groupsByCriterionValue = [];

        foreach ($entryIds as $entryId) {
            $value = (int) ($miniStats[$entryId][$criterion] ?? 0);
            $groupsByCriterionValue[$value] ??= [];
            $groupsByCriterionValue[$value][] = $entryId;
        }

        krsort($groupsByCriterionValue, SORT_NUMERIC);

        $orderedEntryIds = [];
        $manualGroups = [];

        foreach ($groupsByCriterionValue as $entriesWithSameValue) {
            if (count($entriesWithSameValue) === 1) {
                $orderedEntryIds[] = $entriesWithSameValue[0];

                continue;
            }

            $resolvedSubGroup = $this->rankByMiniCriteria(
                entryIds: $entriesWithSameValue,
                miniStats: $miniStats,
                index: $index,
                criteria: $criteria,
                currentCriterion: $currentCriterion + 1,
            );

            $orderedEntryIds = [...$orderedEntryIds, ...$resolvedSubGroup['ordered_entry_ids']];
            $manualGroups = [...$manualGroups, ...$resolvedSubGroup['manual_groups']];
        }

        return [
            'ordered_entry_ids' => $orderedEntryIds,
            'manual_groups' => $manualGroups,
        ];
    }

    /**
     * @param  array<int, int>  $entryIds
     * @return array<int, int>
     */
    protected function sortEntriesByName(array $entryIds, GroupEntryIndex $index): array
    {
        usort($entryIds, function (int $leftEntryId, int $rightEntryId) use ($index): int {
            $leftName = strtolower($index->displayNameForEntry($leftEntryId));
            $rightName = strtolower($index->displayNameForEntry($rightEntryId));

            return [$leftName, $leftEntryId] <=> [$rightName, $rightEntryId];
        });

        return $entryIds;
    }
}
