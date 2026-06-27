<?php

namespace App\Actions\Group;

use App\Actions\Game\CreateGameAction;
use App\Models\Game;
use App\Models\Group;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Game\GameFormatResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerateGroupRoundRobinGamesAction
{
    public function __construct(
        private readonly CreateGameAction $createGame,
    ) {}

    /**
     * @return Collection<int, Game>
     */
    public function __invoke(Group $group): Collection
    {
        $group->loadMissing('competition');
        CompetitionFormatGuard::ensureGroupStage($group->competition);

        $playerIds = $group->groupPlayers()
            ->orderBy('player_id')
            ->pluck('player_id')
            ->map(fn ($playerId) => (int) $playerId)
            ->values()
            ->all();

        if (count($playerIds) < 2) {
            throw ValidationException::withMessages([
                'group' => ['El grupo necesita al menos 2 jugadores.'],
            ]);
        }

        if ($group->games()->exists()) {
            throw ValidationException::withMessages([
                'group' => ['Los partidos del round robin ya fueron generados para este grupo.'],
            ]);
        }

        $round = sprintf('Round Robin - %s', $group->name);
        $competitionId = (int) $group->competition_id;
        $group->loadMissing('competition');
        $matchFormat = GameFormatResolver::resolveForGroup($group->competition);

        return DB::transaction(function () use ($group, $playerIds, $round, $competitionId, $matchFormat): Collection {
            $created = collect();
            $playerCount = count($playerIds);

            for ($index = 0; $index < $playerCount; $index++) {
                for ($pairIndex = $index + 1; $pairIndex < $playerCount; $pairIndex++) {
                    $player1Id = $playerIds[$index];
                    $player2Id = $playerIds[$pairIndex];

                    if ($this->gameExistsBetweenPlayers($competitionId, $player1Id, $player2Id)) {
                        continue;
                    }

                    $created->push(($this->createGame)([
                        'competition_id' => $competitionId,
                        'group_id' => $group->id,
                        'player1_id' => $player1Id,
                        'player2_id' => $player2Id,
                        'round' => $round,
                        'best_of' => $matchFormat['best_of'],
                        'sets_to_win' => $matchFormat['sets_to_win'],
                    ]));
                }
            }

            return $created;
        });
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
