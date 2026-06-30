<?php

namespace App\Actions\Group;

use App\Models\Competition;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupPlayer;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Competition\CompetitionStructureGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerateRandomGroupsForCompetitionAction
{
    public function __construct(
        private readonly GenerateGroupRoundRobinGamesAction $generateRoundRobin,
    ) {}

    /**
     * @return array{
     *     groups_created: int,
     *     players_assigned: int,
     *     games_created: int,
     *     groups: Collection<int, Group>,
     * }
     */
    public function __invoke(Competition $competition, int $groupsCount): array
    {
        CompetitionFormatGuard::ensureGroupStage($competition);
        CompetitionStructureGuard::ensureEditable($competition);

        if ($competition->groups()->exists()) {
            throw ValidationException::withMessages([
                'competition' => ['La competencia ya tiene grupos configurados.'],
            ]);
        }

        $playerIds = $competition->registrations()
            ->orderBy('player_id')
            ->pluck('player_id')
            ->map(fn ($playerId) => (int) $playerId)
            ->values()
            ->all();

        $playerCount = count($playerIds);

        if ($playerCount === 0) {
            throw ValidationException::withMessages([
                'competition' => ['La competencia no tiene jugadores inscriptos.'],
            ]);
        }

        if ($playerCount < 2) {
            throw ValidationException::withMessages([
                'competition' => ['Se requieren al menos 2 jugadores inscriptos para generar grupos.'],
            ]);
        }

        if ($groupsCount > $playerCount) {
            throw ValidationException::withMessages([
                'groups_count' => [
                    'La cantidad de grupos no puede ser mayor que la cantidad de jugadores inscriptos.',
                ],
            ]);
        }

        $groupSizes = $this->calculateBalancedGroupSizes($playerCount, $groupsCount);
        $shuffledPlayerIds = $playerIds;
        shuffle($shuffledPlayerIds);

        $now = now();

        return DB::transaction(function () use (
            $competition,
            $groupsCount,
            $groupSizes,
            $shuffledPlayerIds,
            $playerCount,
            $now,
        ): array {
            $groups = collect();
            $groupPlayersPayload = [];
            $playerOffset = 0;

            for ($groupIndex = 0; $groupIndex < $groupsCount; $groupIndex++) {
                $group = Group::query()->create([
                    'competition_id' => $competition->id,
                    'name' => $this->groupNameForIndex($groupIndex),
                ]);

                $groups->push($group);
                $groupSize = $groupSizes[$groupIndex];

                for ($slot = 0; $slot < $groupSize; $slot++) {
                    $groupPlayersPayload[] = [
                        'group_id' => $group->id,
                        'player_id' => $shuffledPlayerIds[$playerOffset],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $playerOffset++;
                }
            }

            GroupPlayer::query()->insert($groupPlayersPayload);

            $createdGroups = Group::query()
                ->whereIn('id', $groups->pluck('id'))
                ->with([
                    'groupPlayers.player:id,first_name,last_name,nickname',
                ])
                ->orderBy('id')
                ->get();

            $gamesCreated = 0;

            foreach ($createdGroups as $group) {
                if ($group->groupPlayers->count() < 2) {
                    continue;
                }

                if ($group->games()->exists()) {
                    continue;
                }

                $gamesCreated += ($this->generateRoundRobin)($group)->count();
            }

            return [
                'groups_created' => $groupsCount,
                'players_assigned' => $playerCount,
                'games_created' => $gamesCreated,
                'groups' => $createdGroups,
            ];
        });
    }

    /**
     * @return array<int, int>
     */
    private function calculateBalancedGroupSizes(int $playerCount, int $groupsCount): array
    {
        $baseSize = intdiv($playerCount, $groupsCount);
        $remainder = $playerCount % $groupsCount;
        $sizes = [];

        for ($index = 0; $index < $groupsCount; $index++) {
            $sizes[] = $baseSize + ($index < $remainder ? 1 : 0);
        }

        return $sizes;
    }

    private function groupNameForIndex(int $index): string
    {
        $groupNumber = $index + 1;

        if ($groupNumber <= 26) {
            return sprintf('Grupo %s', chr(64 + $groupNumber));
        }

        return sprintf('Grupo %d', $groupNumber);
    }
}
