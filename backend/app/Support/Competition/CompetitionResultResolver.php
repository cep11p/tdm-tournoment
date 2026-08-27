<?php

namespace App\Support\Competition;

use App\Enums\GameStatus;
use App\Enums\ThirdPlaceMode;
use App\Models\Competition;
use App\Models\CompetitionEntry;
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
            ->whereNotNull('winner_entry_id')
            ->with(Game::DISPLAY_RELATIONS)
            ->first();

        if ($finalGame === null) {
            return null;
        }

        $champion = $finalGame->singlesWinner();

        if ($champion === null) {
            return null;
        }

        $runnerUpEntry = (int) $finalGame->winner_entry_id === (int) $finalGame->entry1_id
            ? $finalGame->entry2
            : $finalGame->entry1;
        $runnerUp = $runnerUpEntry?->singlesPlayer();

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
                $thirdPlace = array_values(array_filter(array_map(
                    fn (CompetitionEntry $entry): ?array => self::entryPlayerSummary($entry),
                    BracketPodiumSupport::semifinalLosers($bracket),
                )));
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
                        && $thirdPlaceGame->winner_entry_id !== null
                        && $thirdPlaceGame->entry1_id !== null
                        && $thirdPlaceGame->entry2_id !== null
                    ) {
                        $thirdPlaceGame->loadMissing(Game::DISPLAY_RELATIONS);
                        $thirdPlaceWinner = $thirdPlaceGame->singlesWinner();

                        if ($thirdPlaceWinner !== null) {
                            $thirdPlace = [self::playerSummary($thirdPlaceWinner)];

                            $fourthPlaceEntry = (int) $thirdPlaceGame->winner_entry_id === (int) $thirdPlaceGame->entry1_id
                                ? $thirdPlaceGame->entry2
                                : $thirdPlaceGame->entry1;
                            $fourthPlacePlayer = $fourthPlaceEntry?->singlesPlayer();

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
     * @return array{id: int, name: string}|null
     */
    private static function entryPlayerSummary(CompetitionEntry $entry): ?array
    {
        $player = $entry->singlesPlayer();

        return $player !== null ? self::playerSummary($player) : null;
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
