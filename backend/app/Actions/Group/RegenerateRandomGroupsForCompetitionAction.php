<?php

namespace App\Actions\Group;

use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\Game;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Competition\CompetitionStructureGuard;
use App\Support\Group\RandomGroupDistributionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RegenerateRandomGroupsForCompetitionAction
{
    public function __construct(
        private readonly BuildRandomGroupsForCompetitionAction $buildRandomGroups,
    ) {}

    /**
     * @return array{
     *     groups_removed: int,
     *     games_removed: int,
     *     bracket_removed: bool,
     *     groups_created: int,
     *     players_assigned: int,
     *     games_created: int,
     *     groups: \Illuminate\Support\Collection<int, \App\Models\Group>,
     * }
     */
    public function __invoke(Competition $competition, int $groupsCount): array
    {
        CompetitionFormatGuard::ensureGroupStage($competition);
        CompetitionStructureGuard::ensureEditable($competition);

        if (! $competition->groups()->exists()) {
            throw ValidationException::withMessages([
                'competition' => ['La competencia no tiene grupos para regenerar.'],
            ]);
        }

        $playerCount = $competition->registrations()->count();

        if ($playerCount < 2) {
            throw ValidationException::withMessages([
                'competition' => ['Se requieren al menos 2 jugadores inscriptos para regenerar grupos.'],
            ]);
        }

        RandomGroupDistributionGuard::ensureValid($playerCount, $groupsCount);

        return DB::transaction(function () use ($competition, $groupsCount): array {
            $groupsRemoved = $competition->groups()->count();
            $gamesRemoved = 0;
            $bracketRemoved = false;

            $bracket = $competition->brackets()->first();

            if ($bracket instanceof Bracket) {
                $gamesRemoved += $this->deleteBracketGames($bracket);
                $bracket->delete();
                $bracketRemoved = true;
            }

            $gamesRemoved += Game::query()
                ->where('competition_id', $competition->id)
                ->whereNotNull('group_id')
                ->where('status', GameStatus::Pending)
                ->delete();

            $competition->groups()->delete();

            $buildResult = ($this->buildRandomGroups)($competition, $groupsCount);

            return [
                'groups_removed' => $groupsRemoved,
                'games_removed' => $gamesRemoved,
                'bracket_removed' => $bracketRemoved,
                ...$buildResult,
            ];
        });
    }

    private function deleteBracketGames(Bracket $bracket): int
    {
        return Game::query()
            ->where('bracket_id', $bracket->id)
            ->where(function ($query): void {
                $query->where('status', GameStatus::Pending)
                    ->orWhere('is_bye', true);
            })
            ->delete();
    }
}
