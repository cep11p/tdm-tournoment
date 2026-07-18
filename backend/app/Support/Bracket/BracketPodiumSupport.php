<?php

namespace App\Support\Bracket;

use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Models\Game;
use App\Models\Player;
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
            ->where('bracket_round', $semifinalRound)
            ->with(['player1:id,first_name,last_name', 'player2:id,first_name,last_name'])
            ->orderBy('bracket_match')
            ->get();
    }

    public static function canDetermineThirdPlace(Bracket $bracket): bool
    {
        return self::semifinalLosers($bracket) !== [];
    }

    /**
     * @return array<int, Player>
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

        if (count(array_unique(array_map(fn (Player $player): int => $player->id, $losers))) !== 2) {
            return [];
        }

        return $losers;
    }

    private static function isRealFinishedSemifinal(Game $game): bool
    {
        if ($game->is_bye) {
            return false;
        }

        if ($game->player1_id === null || $game->player2_id === null) {
            return false;
        }

        if ($game->status !== GameStatus::Finished || $game->winner_id === null) {
            return false;
        }

        return true;
    }

    private static function loserOf(Game $game): ?Player
    {
        if ((int) $game->winner_id === (int) $game->player1_id) {
            return $game->player2;
        }

        if ((int) $game->winner_id === (int) $game->player2_id) {
            return $game->player1;
        }

        return null;
    }
}
