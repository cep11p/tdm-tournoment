<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Competition\CompetitionStandingData;
use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompetitionStanding\CompetitionStandingResource;
use App\Models\Competition;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class CompetitionStandingsController extends Controller
{
    public function index(Competition $competition): AnonymousResourceCollection
    {
        $registrations = $competition->registrations()
            ->with('player:id,first_name,last_name')
            ->get();

        $statsByPlayer = $this->initializeStats($registrations);

        $finishedGames = $competition->games()
            ->select(['player1_id', 'player2_id', 'winner_id'])
            ->where('status', GameStatus::Finished)
            ->whereNotNull('winner_id')
            ->get();

        foreach ($finishedGames as $game) {
            $winnerId = (int) $game->winner_id;
            $loserId = $winnerId === (int) $game->player1_id
                ? (int) $game->player2_id
                : (int) $game->player1_id;

            if (isset($statsByPlayer[$winnerId])) {
                $statsByPlayer[$winnerId]['won']++;
            }

            if (isset($statsByPlayer[$loserId])) {
                $statsByPlayer[$loserId]['lost']++;
            }
        }

        $standings = $registrations
            ->map(function ($registration) use ($statsByPlayer): CompetitionStandingData {
                $playerId = (int) $registration->player_id;
                $stats = $statsByPlayer[$playerId] ?? ['won' => 0, 'lost' => 0];
                $playerName = trim(sprintf(
                    '%s %s',
                    (string) $registration->player?->first_name,
                    (string) $registration->player?->last_name
                ));

                return new CompetitionStandingData(
                    playerId: $playerId,
                    playerName: $playerName,
                    won: (int) $stats['won'],
                    lost: (int) $stats['lost'],
                );
            })
            ->sort(function (CompetitionStandingData $left, CompetitionStandingData $right): int {
                return [$right->won, $left->lost, strtolower($left->playerName)]
                    <=>
                    [$left->won, $right->lost, strtolower($right->playerName)];
            })
            ->values();

        return CompetitionStandingResource::collection($standings);
    }

    /**
     * @return array<int, array{won: int, lost: int}>
     */
    private function initializeStats(Collection $registrations): array
    {
        $stats = [];

        foreach ($registrations as $registration) {
            $stats[(int) $registration->player_id] = [
                'won' => 0,
                'lost' => 0,
            ];
        }

        return $stats;
    }
}
