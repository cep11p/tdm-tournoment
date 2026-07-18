<?php

namespace App\Support\Game;

use App\Models\Game;

final class GameDependencyResolver
{
    public function destinationMatchNumber(int $sourceMatch): int
    {
        return (int) (floor(($sourceMatch - 1) / 2) + 1);
    }

    /**
     * @return 'player1_id'|'player2_id'
     */
    public function winnerSlot(int $sourceMatch): string
    {
        return $sourceMatch % 2 === 1 ? 'player1_id' : 'player2_id';
    }

    public function hasRoundBeyondImmediate(Game $source): bool
    {
        if ($source->bracket_id === null || $source->bracket_round === null) {
            return false;
        }

        $nextRound = (int) $source->bracket_round + 1;

        return Game::query()
            ->where('bracket_id', $source->bracket_id)
            ->where('bracket_round', '>', $nextRound)
            ->exists();
    }

    /**
     * @return array{
     *     game: Game,
     *     slot: 'player1_id'|'player2_id',
     *     destination_round: int,
     *     destination_match: int,
     *     expected_player_id: int,
     * }|null
     */
    public function resolveNextRoundDependency(Game $source): ?array
    {
        if ($source->bracket_id === null || $source->bracket_round === null || $source->bracket_match === null) {
            return null;
        }

        if ($source->winner_id === null) {
            return null;
        }

        $destinationRound = (int) $source->bracket_round + 1;
        $destinationMatch = $this->destinationMatchNumber((int) $source->bracket_match);
        $slot = $this->winnerSlot((int) $source->bracket_match);

        $destinationGame = Game::query()
            ->where('bracket_id', $source->bracket_id)
            ->where('bracket_round', $destinationRound)
            ->where('bracket_match', $destinationMatch)
            ->first();

        if ($destinationGame === null) {
            return null;
        }

        return [
            'game' => $destinationGame,
            'slot' => $slot,
            'destination_round' => $destinationRound,
            'destination_match' => $destinationMatch,
            'expected_player_id' => (int) $source->winner_id,
        ];
    }
}
