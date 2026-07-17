<?php

namespace App\Actions\Group;

use App\Actions\Game\CreateGameAction;
use App\Models\Game;
use App\Models\Group;
use App\Support\Game\GameFormatResolver;
use App\Support\Group\RoundRobinScheduleBuilder;
use Illuminate\Support\Collection;

final class BuildGroupRoundRobinGamesAction
{
    public function __construct(
        private readonly CreateGameAction $createGame,
        private readonly RoundRobinScheduleBuilder $scheduleBuilder,
    ) {}

    /**
     * @return Collection<int, Game>
     */
    public function __invoke(Group $group): Collection
    {
        $group->loadMissing('competition');

        $playerIds = $group->groupPlayers()
            ->orderBy('player_id')
            ->pluck('player_id')
            ->map(fn ($playerId) => (int) $playerId)
            ->values()
            ->all();

        $round = sprintf('Round Robin - %s', $group->name);
        $competitionId = (int) $group->competition_id;
        $matchFormat = GameFormatResolver::resolveForGroup($group->competition);
        $schedule = $this->scheduleBuilder->build($playerIds);
        $created = collect();

        foreach ($schedule as $roundIndex => $roundPairings) {
            $groupRound = $roundIndex + 1;

            foreach ($roundPairings as $matchIndex => $pairing) {
                $player1Id = $pairing['player1_id'];
                $player2Id = $pairing['player2_id'];

                if ($this->gameExistsBetweenPlayers($competitionId, $player1Id, $player2Id)) {
                    continue;
                }

                $created->push(($this->createGame)([
                    'competition_id' => $competitionId,
                    'group_id' => $group->id,
                    'player1_id' => $player1Id,
                    'player2_id' => $player2Id,
                    'round' => $round,
                    'group_round' => $groupRound,
                    'group_match' => $matchIndex + 1,
                    'best_of' => $matchFormat['best_of'],
                    'sets_to_win' => $matchFormat['sets_to_win'],
                ]));
            }
        }

        return $created;
    }

    private function gameExistsBetweenPlayers(int $competitionId, int $player1Id, int $player2Id): bool
    {
        return Game::query()
            ->where('competition_id', $competitionId)
            ->where(function ($query) use ($player1Id, $player2Id): void {
                $query->where(function ($query) use ($player1Id, $player2Id): void {
                    $query->where('player1_id', $player1Id)
                        ->where('player2_id', $player2Id);
                })->orWhere(function ($query) use ($player1Id, $player2Id): void {
                    $query->where('player1_id', $player2Id)
                        ->where('player2_id', $player1Id);
                });
            })
            ->exists();
    }
}
