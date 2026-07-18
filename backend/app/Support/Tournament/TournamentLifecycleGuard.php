<?php

namespace App\Support\Tournament;

use App\Enums\TournamentStatus;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Group;
use App\Models\Tournament;
use Illuminate\Validation\ValidationException;

final class TournamentLifecycleGuard
{
    public const LOCK_MESSAGE = 'No se pueden realizar modificaciones deportivas porque el torneo está finalizado.';

    public static function ensureMutable(Tournament $tournament, string $field = 'tournament'): void
    {
        if ($tournament->status === TournamentStatus::Finished) {
            throw ValidationException::withMessages([
                $field => [self::LOCK_MESSAGE],
            ]);
        }
    }

    public static function ensureMutableForCompetition(Competition $competition, string $field = 'tournament'): void
    {
        $competition->loadMissing('tournament');
        self::ensureMutable($competition->tournament, $field);
    }

    public static function ensureMutableForGroup(Group $group, string $field = 'tournament'): void
    {
        $group->loadMissing('competition.tournament');
        self::ensureMutableForCompetition($group->competition, $field);
    }

    public static function ensureMutableForGame(Game $game, string $field = 'tournament'): void
    {
        $game->loadMissing('competition.tournament');
        self::ensureMutableForCompetition($game->competition, $field);
    }

    public static function ensureMutableForBracket(Bracket $bracket, string $field = 'tournament'): void
    {
        $bracket->loadMissing('competition.tournament');
        self::ensureMutableForCompetition($bracket->competition, $field);
    }
}
