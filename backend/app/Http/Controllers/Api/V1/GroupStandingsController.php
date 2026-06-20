<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Competition\CompetitionStandingData;
use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompetitionStanding\CompetitionStandingResource;
use App\Models\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class GroupStandingsController extends Controller
{
    public function index(Group $group): AnonymousResourceCollection
    {
        $groupPlayers = $group->groupPlayers()
            ->with('player:id,first_name,last_name')
            ->get();

        $statsByPlayer = $this->initializeStats($groupPlayers);

        $finishedGames = $group->games()
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

        $standings = $groupPlayers
            ->map(function ($groupPlayer) use ($statsByPlayer): CompetitionStandingData {
                $playerId = (int) $groupPlayer->player_id;
                $stats = $statsByPlayer[$playerId] ?? ['won' => 0, 'lost' => 0];
                $playerName = trim(sprintf(
                    '%s %s',
                    (string) $groupPlayer->player?->first_name,
                    (string) $groupPlayer->player?->last_name
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
    private function initializeStats(Collection $groupPlayers): array
    {
        $stats = [];

        foreach ($groupPlayers as $groupPlayer) {
            $stats[(int) $groupPlayer->player_id] = [
                'won' => 0,
                'lost' => 0,
            ];
        }

        return $stats;
    }
}
