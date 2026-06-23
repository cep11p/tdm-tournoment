<?php

namespace App\Support\Competition;

use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Player;

final class CompetitionResultResolver
{
    /**
     * @return array{
     *     champion: array{id: int, name: string},
     *     runner_up: array{id: int, name: string},
     *     final_game_id: int,
     * }|null
     */
    public static function resolve(Competition $competition): ?array
    {
        $finalGame = Game::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('bracket_id')
            ->where('round', 'Final')
            ->where('status', GameStatus::Finished)
            ->whereNotNull('winner_id')
            ->with(['player1', 'player2', 'winner'])
            ->first();

        if ($finalGame === null) {
            return null;
        }

        $champion = $finalGame->winner;

        if ($champion === null) {
            return null;
        }

        $runnerUp = (int) $finalGame->winner_id === (int) $finalGame->player1_id
            ? $finalGame->player2
            : $finalGame->player1;

        if ($runnerUp === null || ! $runnerUp->id) {
            return null;
        }

        return [
            'champion' => self::playerSummary($champion),
            'runner_up' => self::playerSummary($runnerUp),
            'final_game_id' => $finalGame->id,
        ];
    }

    /**
     * @return array{id: int, name: string}
     */
    private static function playerSummary(Player $player): array
    {
        return [
            'id' => $player->id,
            'name' => trim(sprintf('%s %s', $player->first_name, $player->last_name)),
        ];
    }
}
