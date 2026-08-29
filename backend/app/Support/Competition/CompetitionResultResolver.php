<?php

namespace App\Support\Competition;

use App\Enums\GameStatus;
use App\Enums\ThirdPlaceMode;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Support\Bracket\BracketPodiumSupport;

final class CompetitionResultResolver
{
    /**
     * @return array{
     *     champion: array{
     *         competition_entry_id: int,
     *         display_name: string,
     *         members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>,
     *         id: int|null,
     *         name: string|null,
     *     },
     *     runner_up: array{
     *         competition_entry_id: int,
     *         display_name: string,
     *         members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>,
     *         id: int|null,
     *         name: string|null,
     *     },
     *     final_game_id: int,
     *     third_place_mode: string,
     *     third_place: list<array{
     *         competition_entry_id: int,
     *         display_name: string,
     *         members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>,
     *         id: int|null,
     *         name: string|null,
     *     }>,
     *     fourth_place: array{
     *         competition_entry_id: int,
     *         display_name: string,
     *         members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>,
     *         id: int|null,
     *         name: string|null,
     *     }|null,
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

        $championEntry = $finalGame->winnerEntry;

        if ($championEntry === null) {
            return null;
        }

        $runnerUpEntry = (int) $finalGame->winner_entry_id === (int) $finalGame->entry1_id
            ? $finalGame->entry2
            : $finalGame->entry1;

        if ($runnerUpEntry === null) {
            return null;
        }

        $champion = CompetitionEntrySummaryPayload::forEntry($championEntry, $competition);
        $runnerUp = CompetitionEntrySummaryPayload::forEntry($runnerUpEntry, $competition);

        if ($champion === null || $runnerUp === null) {
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
                    fn (CompetitionEntry $entry): ?array => CompetitionEntrySummaryPayload::forEntry($entry, $competition),
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

                        $thirdPlaceWinnerEntry = $thirdPlaceGame->winnerEntry;

                        if ($thirdPlaceWinnerEntry !== null) {
                            $thirdPlaceSummary = CompetitionEntrySummaryPayload::forEntry(
                                $thirdPlaceWinnerEntry,
                                $competition,
                            );

                            if ($thirdPlaceSummary !== null) {
                                $thirdPlace = [$thirdPlaceSummary];
                            }

                            $fourthPlaceEntry = (int) $thirdPlaceGame->winner_entry_id === (int) $thirdPlaceGame->entry1_id
                                ? $thirdPlaceGame->entry2
                                : $thirdPlaceGame->entry1;

                            if ($fourthPlaceEntry !== null) {
                                $fourthPlace = CompetitionEntrySummaryPayload::forEntry($fourthPlaceEntry, $competition);
                            }
                        }
                    }
                }
            }
        }

        return [
            'champion' => $champion,
            'runner_up' => $runnerUp,
            'final_game_id' => $finalGame->id,
            'third_place_mode' => $thirdPlaceMode->value,
            'third_place' => $thirdPlace,
            'fourth_place' => $fourthPlace,
            'third_place_game_id' => $thirdPlaceGameId,
        ];
    }
}
