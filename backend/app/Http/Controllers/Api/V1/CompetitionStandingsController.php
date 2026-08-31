<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Competition\CompetitionStandingData;
use App\Enums\CompetitionType;
use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompetitionStanding\CompetitionStandingResource;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Support\Competition\CompetitionEntryDisplayName;
use App\Support\Competition\CompetitionEntryMemberPayload;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CompetitionStandingsController extends Controller
{
    public function index(Competition $competition): AnonymousResourceCollection
    {
        if ($this->isTeamCompetition($competition)) {
            throw ValidationException::withMessages([
                'competition' => ['Las posiciones generales de competencias por equipos se consultan por grupo.'],
            ]);
        }

        $competition->loadMissing('entries.members.player');

        $entries = $competition->entries;
        $statsByEntry = $this->initializeStats($entries);
        $isSingles = $this->isSinglesCompetition($competition);

        $finishedGames = $competition->games()
            ->select(['entry1_id', 'entry2_id', 'winner_entry_id'])
            ->where('status', GameStatus::Finished)
            ->whereNotNull('winner_entry_id')
            ->get();

        foreach ($finishedGames as $game) {
            $winnerEntryId = (int) $game->winner_entry_id;
            $loserEntryId = (int) $game->winner_entry_id === (int) $game->entry1_id
                ? ($game->entry2_id !== null ? (int) $game->entry2_id : null)
                : (int) $game->entry1_id;

            if (isset($statsByEntry[$winnerEntryId])) {
                $statsByEntry[$winnerEntryId]['won']++;
            }

            if ($loserEntryId !== null && isset($statsByEntry[$loserEntryId])) {
                $statsByEntry[$loserEntryId]['lost']++;
            }
        }

        $standings = $entries
            ->map(function (CompetitionEntry $entry) use ($statsByEntry, $isSingles): CompetitionStandingData {
                $entryId = (int) $entry->id;
                $stats = $statsByEntry[$entryId] ?? ['won' => 0, 'lost' => 0];
                $displayName = CompetitionEntryDisplayName::for($entry);
                $members = CompetitionEntryMemberPayload::forEntry($entry);

                $playerId = null;
                $playerName = null;

                if ($isSingles) {
                    $player = $members[0] ?? null;
                    $playerId = $player['id'] ?? null;
                    $playerName = $player !== null
                        ? trim(sprintf('%s %s', (string) $player['first_name'], (string) $player['last_name']))
                        : null;
                    $playerName = $playerName !== '' ? $playerName : null;
                }

                return new CompetitionStandingData(
                    competitionEntryId: $entryId,
                    displayName: $displayName,
                    members: $members,
                    playerId: $playerId,
                    playerName: $playerName,
                    won: (int) $stats['won'],
                    lost: (int) $stats['lost'],
                );
            })
            ->sort(function (CompetitionStandingData $left, CompetitionStandingData $right): int {
                return [$right->won, $left->lost, strtolower($left->displayName)]
                    <=>
                    [$left->won, $right->lost, strtolower($right->displayName)];
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
            $stats[(int) $entry->id] = [
                'won' => 0,
                'lost' => 0,
            ];
        }

        return $stats;
    }

    private function isSinglesCompetition(Competition $competition): bool
    {
        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        return $type === CompetitionType::Singles;
    }

    private function isTeamCompetition(Competition $competition): bool
    {
        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        return $type === CompetitionType::Team;
    }
}
