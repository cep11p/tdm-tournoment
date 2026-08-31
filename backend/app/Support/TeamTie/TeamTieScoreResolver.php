<?php

namespace App\Support\TeamTie;

use App\Models\TeamTie;

final class TeamTieScoreResolver
{
    /**
     * @return array{
     *     entry1: int,
     *     entry2: int,
     *     rubbers_counting: int,
     *     rubbers_finished: int,
     *     rubbers_total: int,
     *     is_decided: bool,
     * }
     */
    public static function resolve(TeamTie $teamTie): array
    {
        $outcome = TeamTieOutcomeResolver::resolve($teamTie);

        return [
            'entry1' => $outcome['entry1_wins'],
            'entry2' => $outcome['entry2_wins'],
            'rubbers_counting' => $outcome['rubbers_counting'],
            'rubbers_finished' => $outcome['rubbers_finished_total'],
            'rubbers_total' => $outcome['rubbers_total'],
            'is_decided' => $outcome['is_decided'],
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
