<?php

namespace App\Support\Group;

use App\Data\Group\GroupSheetResolvedGame;
use App\Models\CompetitionEntry;
use App\Models\Game;
use Illuminate\Support\Collection;

final class GroupSheetGameResolver
{
    /**
     * Localiza cada pairing de planilla en los Game persistidos.
     *
     * La identidad del pairing es unordered (1-3 coincide con entry1=3/entry2=1),
     * pero el resultado conserva la orientación side1/side2 del patrón.
     *
     * @param  Collection<int, CompetitionEntry>  $entriesById
     * @param  array<int, int>  $sheetNumberByEntryId  competition_entry_id => sheet_number
     * @param  Collection<int, Game>  $games
     * @return list<GroupSheetResolvedGame>
     */
    public static function resolve(
        int $size,
        Collection $entriesById,
        array $sheetNumberByEntryId,
        Collection $games,
    ): array {
        if (! GroupSheetPlayingOrder::supports($size) || $entriesById->count() !== $size) {
            throw GroupSheetFixtureMismatchException::forGroup();
        }

        $fixtures = GroupSheetPlayingOrder::forSize($size);
        $entryIdBySheetNumber = self::entryIdBySheetNumber($sheetNumberByEntryId, $size);
        $gamesByPair = self::indexGamesByUnorderedPair($games, $entriesById);

        if (count($gamesByPair) !== count($fixtures)) {
            throw GroupSheetFixtureMismatchException::forGroup();
        }

        $resolved = [];

        foreach ($fixtures as $fixture) {
            $side1Id = $entryIdBySheetNumber[$fixture->side1];
            $side2Id = $entryIdBySheetNumber[$fixture->side2];
            $key = self::unorderedPairKey($side1Id, $side2Id);
            $game = $gamesByPair[$key] ?? null;

            if ($game === null) {
                throw GroupSheetFixtureMismatchException::forGroup();
            }

            unset($gamesByPair[$key]);

            $side1 = $entriesById->get($side1Id);
            $side2 = $entriesById->get($side2Id);

            if (! $side1 instanceof CompetitionEntry || ! $side2 instanceof CompetitionEntry) {
                throw GroupSheetFixtureMismatchException::forGroup();
            }

            $resolved[] = new GroupSheetResolvedGame(
                game: $game,
                fixture: $fixture,
                side1: $side1,
                side2: $side2,
            );
        }

        if ($gamesByPair !== []) {
            throw GroupSheetFixtureMismatchException::forGroup();
        }

        return $resolved;
    }

    /**
     * @param  array<int, int>  $sheetNumberByEntryId
     * @return array<int, int> sheet_number => competition_entry_id
     */
    private static function entryIdBySheetNumber(array $sheetNumberByEntryId, int $size): array
    {
        $entryIdBySheetNumber = array_flip($sheetNumberByEntryId);

        if (count($entryIdBySheetNumber) !== $size) {
            throw GroupSheetFixtureMismatchException::forGroup();
        }

        for ($number = 1; $number <= $size; $number++) {
            if (! isset($entryIdBySheetNumber[$number])) {
                throw GroupSheetFixtureMismatchException::forGroup();
            }

            $entryIdBySheetNumber[$number] = (int) $entryIdBySheetNumber[$number];
        }

        return $entryIdBySheetNumber;
    }

    /**
     * @param  Collection<int, Game>  $games
     * @param  Collection<int, CompetitionEntry>  $entriesById
     * @return array<string, Game>
     */
    private static function indexGamesByUnorderedPair(Collection $games, Collection $entriesById): array
    {
        $gamesByPair = [];

        foreach ($games as $game) {
            if ($game->is_bye || $game->entry1_id === null || $game->entry2_id === null) {
                throw GroupSheetFixtureMismatchException::forGroup();
            }

            $entry1Id = (int) $game->entry1_id;
            $entry2Id = (int) $game->entry2_id;

            if (! $entriesById->has($entry1Id) || ! $entriesById->has($entry2Id)) {
                throw GroupSheetFixtureMismatchException::forGroup();
            }

            $key = self::unorderedPairKey($entry1Id, $entry2Id);

            if (isset($gamesByPair[$key])) {
                throw GroupSheetFixtureMismatchException::forGroup();
            }

            $gamesByPair[$key] = $game;
        }

        return $gamesByPair;
    }

    private static function unorderedPairKey(int $left, int $right): string
    {
        $pair = [$left, $right];
        sort($pair);

        return sprintf('%d-%d', $pair[0], $pair[1]);
    }
}
