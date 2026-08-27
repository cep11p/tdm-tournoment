<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Competition\CompetitionStandingData;
use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompetitionStanding\CompetitionStandingResource;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class CompetitionStandingsController extends Controller
{
    public function index(Competition $competition): AnonymousResourceCollection
    {
        $entries = $competition->entries()
            ->with(['members.player:id,first_name,last_name'])
            ->get();

        $statsByPlayer = $this->initializeStats($entries);

        $finishedGames = $competition->games()
            ->select(['entry1_id', 'entry2_id', 'winner_entry_id'])
            ->with([
                'entry1.members',
                'entry2.members',
                'winnerEntry.members',
            ])
            ->where('status', GameStatus::Finished)
            ->whereNotNull('winner_entry_id')
            ->get();

        foreach ($finishedGames as $game) {
            $winnerId = $game->singlesWinnerId();
            $loserEntry = (int) $game->winner_entry_id === (int) $game->entry1_id
                ? $game->entry2
                : $game->entry1;
            $loserId = $loserEntry?->singlesPlayerId();

            if (isset($statsByPlayer[$winnerId])) {
                $statsByPlayer[$winnerId]['won']++;
            }

            if ($loserId !== null && isset($statsByPlayer[$loserId])) {
                $statsByPlayer[$loserId]['lost']++;
            }
        }

        $standings = $entries
            ->map(function (CompetitionEntry $entry) use ($statsByPlayer): CompetitionStandingData {
                $playerId = (int) ($entry->singlesPlayerId() ?? 0);
                $stats = $statsByPlayer[$playerId] ?? ['won' => 0, 'lost' => 0];
                $player = $entry->singlesPlayer();
                $playerName = trim(sprintf(
                    '%s %s',
                    (string) $player?->first_name,
                    (string) $player?->last_name
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
     * @param  Collection<int, CompetitionEntry>  $entries
     * @return array<int, array{won: int, lost: int}>
     */
    private function initializeStats(Collection $entries): array
    {
        $stats = [];

        foreach ($entries as $entry) {
            $playerId = (int) ($entry->singlesPlayerId() ?? 0);

            if ($playerId <= 0) {
                continue;
            }

            $stats[$playerId] = [
                'won' => 0,
                'lost' => 0,
            ];
        }

        return $stats;
    }
}
