<?php

namespace App\Support\Bracket;

use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Enums\ThirdPlaceMode;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Game;
use Illuminate\Support\Collection;

final class BracketPodiumSupport
{
    public static function finalRound(Bracket $bracket): int
    {
        return (int) log((int) $bracket->bracket_size, 2);
    }

    public static function semifinalRound(Bracket $bracket): ?int
    {
        if ((int) $bracket->bracket_size < 4) {
            return null;
        }

        return self::finalRound($bracket) - 1;
    }

    public static function isSemifinalRound(Bracket $bracket, int $round): bool
    {
        $semifinalRound = self::semifinalRound($bracket);

        return $semifinalRound !== null && $semifinalRound === $round;
    }

    /**
     * @return Collection<int, Game>
     */
    public static function semifinalGames(Bracket $bracket): Collection
    {
        $semifinalRound = self::semifinalRound($bracket);

        if ($semifinalRound === null) {
            return collect();
        }

        return Game::query()
            ->where('bracket_id', $bracket->id)
            ->mainBracket()
            ->where('bracket_round', $semifinalRound)
            ->with(Game::DISPLAY_RELATIONS)
            ->orderBy('bracket_match')
            ->get();
    }

    public static function canDetermineThirdPlace(Bracket $bracket): bool
    {
        return self::semifinalLosers($bracket) !== [];
    }

    public static function requiresPlayoffThirdPlace(Competition $competition, Bracket $bracket): bool
    {
        $thirdPlaceMode = $competition->third_place_mode instanceof ThirdPlaceMode
            ? $competition->third_place_mode
            : ThirdPlaceMode::from((string) $competition->third_place_mode);

        return $thirdPlaceMode === ThirdPlaceMode::Playoff
            && self::canDetermineThirdPlace($bracket);
    }

    public static function findThirdPlaceGame(Bracket $bracket): ?Game
    {
        return Game::query()
            ->where('bracket_id', $bracket->id)
            ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
            ->first();
    }

    /**
     * @return array<int, CompetitionEntry>
     */
    public static function thirdPlaceParticipants(Bracket $bracket): array
    {
        return self::semifinalLosers($bracket);
    }

    /**
     * @return array<int, CompetitionEntry>
     */
    public static function semifinalLosers(Bracket $bracket): array
    {
        if ((int) $bracket->bracket_size < 4) {
            return [];
        }

        $semifinals = self::semifinalGames($bracket);

        if ($semifinals->count() !== 2) {
            return [];
        }

        $losers = [];

        foreach ($semifinals as $game) {
            if (! self::isRealFinishedSemifinal($game)) {
                return [];
            }

            $loser = self::loserOf($game);

            if ($loser === null) {
                return [];
            }

            $losers[] = $loser;
        }

        if (count(array_unique(array_map(fn (CompetitionEntry $entry): int => $entry->id, $losers))) !== 2) {
            return [];
        }

        return $losers;
    }

    private static function isRealFinishedSemifinal(Game $game): bool
    {
        if ($game->is_bye) {
            return false;
        }

        if ($game->entry1_id === null || $game->entry2_id === null) {
            return false;
        }

        if ($game->status !== GameStatus::Finished || $game->winner_entry_id === null) {
            return false;
        }

        return true;
    }

    private static function loserOf(Game $game): ?CompetitionEntry
    {
        if ((int) $game->winner_entry_id === (int) $game->entry1_id) {
            return $game->entry2;
        }

        if ((int) $game->winner_entry_id === (int) $game->entry2_id) {
            return $game->entry1;
        }

        return null;
    }
}
