<?php

namespace App\Support\TeamTie;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\TeamTie;

final class TeamTieOutcomeResolver
{
    /**
     * @return array{
     *     entry1_wins: int,
     *     entry2_wins: int,
     *     victories_required: int,
     *     winner_entry_id: int|null,
     *     is_decided: bool,
     *     clinch_slot_order: int|null,
     *     rubbers_counting: int,
     *     rubbers_finished_total: int,
     *     rubbers_total: int,
     *     slots_to_mark_not_needed: list<int>,
     *     slots_to_reopen: list<int>,
     * }
     */
    public static function resolve(TeamTie $teamTie): array
    {
        $teamTie->loadMissing([
            'teamTieGames' => fn ($query) => $query->orderBy('slot_order'),
            'teamTieGames.game.sets',
        ]);

        $entry1Id = (int) $teamTie->entry1_id;
        $entry2Id = (int) $teamTie->entry2_id;
        $victoriesRequired = (int) $teamTie->victories_required;

        $entry1Wins = 0;
        $entry2Wins = 0;
        $rubbersCounting = 0;
        $rubbersFinishedTotal = 0;
        $clinchSlotOrder = null;
        $winnerEntryId = null;
        $isDecided = false;

        $slotsToMarkNotNeeded = [];
        $slotsToReopen = [];

        foreach ($teamTie->teamTieGames as $teamTieGame) {
            $game = $teamTieGame->game;
            $slotOrder = (int) $teamTieGame->slot_order;

            if (self::isFinishedRubber($game)) {
                $rubbersFinishedTotal++;
            }

            if ($isDecided) {
                if (self::canMarkNotNeeded($game)) {
                    $slotsToMarkNotNeeded[] = $slotOrder;
                }

                continue;
            }

            if ($game?->status === GameStatus::NotNeeded) {
                if (self::canReopen($game)) {
                    $slotsToReopen[] = $slotOrder;
                }

                continue;
            }

            if (! self::isFinishedRubber($game)) {
                continue;
            }

            $rubbersCounting++;
            $winnerId = (int) $game->winner_entry_id;

            if ($winnerId === $entry1Id) {
                $entry1Wins++;
            } elseif ($winnerId === $entry2Id) {
                $entry2Wins++;
            }

            if ($entry1Wins >= $victoriesRequired) {
                $isDecided = true;
                $winnerEntryId = $entry1Id;
                $clinchSlotOrder = $slotOrder;
            } elseif ($entry2Wins >= $victoriesRequired) {
                $isDecided = true;
                $winnerEntryId = $entry2Id;
                $clinchSlotOrder = $slotOrder;
            }
        }

        return [
            'entry1_wins' => $entry1Wins,
            'entry2_wins' => $entry2Wins,
            'victories_required' => $victoriesRequired,
            'winner_entry_id' => $winnerEntryId,
            'is_decided' => $isDecided,
            'clinch_slot_order' => $clinchSlotOrder,
            'rubbers_counting' => $rubbersCounting,
            'rubbers_finished_total' => $rubbersFinishedTotal,
            'rubbers_total' => $teamTie->teamTieGames->count(),
            'slots_to_mark_not_needed' => array_values(array_unique($slotsToMarkNotNeeded)),
            'slots_to_reopen' => array_values(array_unique($slotsToReopen)),
        ];
    }

    private static function isFinishedRubber(?Game $game): bool
    {
        return $game !== null
            && $game->status === GameStatus::Finished
            && $game->winner_entry_id !== null;
    }

    private static function canMarkNotNeeded(?Game $game): bool
    {
        return $game !== null
            && $game->status === GameStatus::Pending
            && ! self::gameHasSets($game);
    }

    private static function canReopen(?Game $game): bool
    {
        return $game !== null
            && $game->status === GameStatus::NotNeeded
            && ! self::gameHasSets($game);
    }

    private static function gameHasSets(Game $game): bool
    {
        if ($game->relationLoaded('sets')) {
            return $game->sets->isNotEmpty();
        }

        return $game->sets()->exists();
    }
}
