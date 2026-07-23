<?php

namespace App\Support\Game;

use App\Enums\ThirdPlaceMode;
use App\Models\Game;
use App\Support\Bracket\BracketPodiumSupport;

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
            ->mainBracket()
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
    public function resolveWinnerDependency(Game $source): ?array
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
            ->mainBracket()
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

    /**
     * @return array{
     *     game: Game,
     *     slot: 'player1_id'|'player2_id',
     *     expected_player_id: int,
     * }|null
     */
    public function resolveLoserThirdPlaceDependency(Game $source): ?array
    {
        if ($source->bracket_id === null || $source->bracket_round === null || $source->bracket_match === null) {
            return null;
        }

        if ($source->winner_id === null || $source->player1_id === null || $source->player2_id === null) {
            return null;
        }

        $source->loadMissing(['competition', 'bracket']);

        $bracket = $source->bracket;
        $competition = $source->competition;

        if ($bracket === null || $competition === null) {
            return null;
        }

        if (! BracketPodiumSupport::isSemifinalRound($bracket, (int) $source->bracket_round)) {
            return null;
        }

        $thirdPlaceMode = $competition->third_place_mode instanceof ThirdPlaceMode
            ? $competition->third_place_mode
            : ThirdPlaceMode::from((string) $competition->third_place_mode);

        if ($thirdPlaceMode !== ThirdPlaceMode::Playoff) {
            return null;
        }

        $thirdPlaceGame = BracketPodiumSupport::findThirdPlaceGame($bracket);

        if ($thirdPlaceGame === null) {
            return null;
        }

        $winnerId = (int) $source->winner_id;
        $loserId = $winnerId === (int) $source->player1_id
            ? (int) $source->player2_id
            : (int) $source->player1_id;

        return [
            'game' => $thirdPlaceGame,
            'slot' => $this->winnerSlot((int) $source->bracket_match),
            'expected_player_id' => $loserId,
        ];
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
        return $this->resolveWinnerDependency($source);
    }
}
