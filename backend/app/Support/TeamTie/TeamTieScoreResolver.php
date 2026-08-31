<?php

namespace App\Support\TeamTie;

use App\Enums\GameStatus;
use App\Models\TeamTie;

final class TeamTieScoreResolver
{
    /**
     * @return array{
     *     entry1: int,
     *     entry2: int,
     *     rubbers_finished: int,
     *     rubbers_total: int,
     * }
     */
    public static function resolve(TeamTie $teamTie): array
    {
        $teamTie->loadMissing([
            'teamTieGames.game:id,status,winner_entry_id',
        ]);

        $entry1Id = (int) $teamTie->entry1_id;
        $entry2Id = (int) $teamTie->entry2_id;
        $entry1Wins = 0;
        $entry2Wins = 0;
        $rubbersFinished = 0;
        $rubbersTotal = $teamTie->teamTieGames->count();

        foreach ($teamTie->teamTieGames as $teamTieGame) {
            $game = $teamTieGame->game;

            if ($game === null || $game->status !== GameStatus::Finished || $game->winner_entry_id === null) {
                continue;
            }

            $rubbersFinished++;

            if ((int) $game->winner_entry_id === $entry1Id) {
                $entry1Wins++;
            } elseif ((int) $game->winner_entry_id === $entry2Id) {
                $entry2Wins++;
            }
        }

        return [
            'entry1' => $entry1Wins,
            'entry2' => $entry2Wins,
            'rubbers_finished' => $rubbersFinished,
            'rubbers_total' => $rubbersTotal,
        ];
    }

    public static function rubbersWithLineupCount(TeamTie $teamTie): int
    {
        $teamTie->loadMissing('teamTieGames.members');

        return $teamTie->teamTieGames
            ->filter(fn ($teamTieGame): bool => $teamTieGame->isLineupComplete())
            ->count();
    }
}
