<?php

namespace App\Actions\Group;

use App\Actions\Game\CreateGameAction;
use App\Models\Game;
use App\Models\Group;
use App\Support\Game\GameFormatResolver;
use App\Support\Competition\TeamCompetitionSchedulingGuard;
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

        TeamCompetitionSchedulingGuard::ensureRoundRobinAllowed($group->competition);

        $entryIds = $group->groupEntries()
            ->orderBy('competition_entry_id')
            ->pluck('competition_entry_id')
            ->map(fn ($entryId) => (int) $entryId)
            ->values()
            ->all();

        $round = sprintf('Round Robin - %s', $group->name);
        $competitionId = (int) $group->competition_id;
        $matchFormat = GameFormatResolver::resolveForGroup($group->competition);
        $schedule = $this->scheduleBuilder->build($entryIds);
        $created = collect();

        foreach ($schedule as $roundIndex => $roundPairings) {
            $groupRound = $roundIndex + 1;

            foreach ($roundPairings as $matchIndex => $pairing) {
                $entry1Id = $pairing['entry1_id'];
                $entry2Id = $pairing['entry2_id'];

                if ($this->gameExistsBetweenEntries($competitionId, $entry1Id, $entry2Id)) {
                    continue;
                }

                $created->push(($this->createGame)([
                    'competition_id' => $competitionId,
                    'group_id' => $group->id,
                    'entry1_id' => $entry1Id,
                    'entry2_id' => $entry2Id,
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

    private function gameExistsBetweenEntries(int $competitionId, int $entry1Id, int $entry2Id): bool
    {
        return Game::query()
            ->where('competition_id', $competitionId)
            ->where(function ($query) use ($entry1Id, $entry2Id): void {
                $query->where(function ($query) use ($entry1Id, $entry2Id): void {
                    $query->where('entry1_id', $entry1Id)
                        ->where('entry2_id', $entry2Id);
                })->orWhere(function ($query) use ($entry1Id, $entry2Id): void {
                    $query->where('entry1_id', $entry2Id)
                        ->where('entry2_id', $entry1Id);
                });
            })
            ->exists();
    }
}
