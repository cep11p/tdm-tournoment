<?php

namespace App\Support\Competition;

use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Enums\ThirdPlaceMode;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Player;
use App\Support\Bracket\BracketPodiumSupport;

final class CompetitionResultResolver
{
    /**
     * @return array{
     *     champion: array{id: int, name: string},
     *     runner_up: array{id: int, name: string},
     *     final_game_id: int,
     *     third_place_mode: string,
     *     third_place: list<array{id: int, name: string}>,
     *     fourth_place: array{id: int, name: string}|null,
     *     third_place_game_id: int|null,
     * }|null
     */
    public static function resolve(Competition $competition): ?array
    {
        $finalGame = Game::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('bracket_id')
            ->mainBracket()
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

        $thirdPlaceMode = $competition->third_place_mode instanceof ThirdPlaceMode
            ? $competition->third_place_mode
            : ThirdPlaceMode::from((string) $competition->third_place_mode);

        $thirdPlace = [];
        $fourthPlace = null;
        $thirdPlaceGameId = null;

        if ($thirdPlaceMode === ThirdPlaceMode::Shared) {
            $bracket = $competition->brackets()->first();

            if ($bracket !== null && BracketPodiumSupport::canDetermineThirdPlace($bracket)) {
                $thirdPlace = array_map(
                    fn (Player $player): array => self::playerSummary($player),
                    BracketPodiumSupport::semifinalLosers($bracket),
                );
            }
        }

        if ($thirdPlaceMode === ThirdPlaceMode::Playoff) {
            $bracket = $competition->brackets()->first();

            if ($bracket !== null && BracketPodiumSupport::requiresPlayoffThirdPlace($competition, $bracket)) {
                $thirdPlaceGame = BracketPodiumSupport::findThirdPlaceGame($bracket);

                if ($thirdPlaceGame !== null) {
                    $thirdPlaceGameId = $thirdPlaceGame->id;

                    if (
                        $thirdPlaceGame->status === GameStatus::Finished
                        && $thirdPlaceGame->winner_id !== null
                        && $thirdPlaceGame->player1_id !== null
                        && $thirdPlaceGame->player2_id !== null
                    ) {
                        $thirdPlaceGame->loadMissing(['player1', 'player2', 'winner']);
                        $thirdPlaceWinner = $thirdPlaceGame->winner;

                        if ($thirdPlaceWinner !== null) {
                            $thirdPlace = [self::playerSummary($thirdPlaceWinner)];

                            $fourthPlacePlayer = (int) $thirdPlaceGame->winner_id === (int) $thirdPlaceGame->player1_id
                                ? $thirdPlaceGame->player2
                                : $thirdPlaceGame->player1;

                            if ($fourthPlacePlayer !== null && $fourthPlacePlayer->id) {
                                $fourthPlace = self::playerSummary($fourthPlacePlayer);
                            }
                        }
                    }
                }
            }
        }

        return [
            'champion' => self::playerSummary($champion),
            'runner_up' => self::playerSummary($runnerUp),
            'final_game_id' => $finalGame->id,
            'third_place_mode' => $thirdPlaceMode->value,
            'third_place' => $thirdPlace,
            'fourth_place' => $fourthPlace,
            'third_place_game_id' => $thirdPlaceGameId,
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
