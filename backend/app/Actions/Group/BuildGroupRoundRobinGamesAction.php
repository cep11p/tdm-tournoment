<?php

namespace App\Actions\Group;

use App\Actions\Game\CreateGameAction;
use App\Models\Game;
use App\Models\Group;
use App\Support\Competition\TeamCompetitionSchedulingGuard;
use App\Support\Game\GameFormatResolver;
use App\Support\Group\GroupSheetNumbering;
use App\Support\Group\GroupSheetPlayingOrder;
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

        TeamCompetitionSchedulingGuard::ensureGamesRoundRobinAllowed($group->competition);

        $entryIds = $group->groupEntries()
            ->orderBy('competition_entry_id')
            ->pluck('competition_entry_id')
            ->map(fn ($entryId) => (int) $entryId)
            ->values()
            ->all();

        $round = sprintf('Round Robin - %s', $group->name);
        $competitionId = (int) $group->competition_id;
        $matchFormat = GameFormatResolver::resolveForGroup($group->competition);
        $created = collect();

        foreach ($this->scheduleSlots($entryIds) as $slot) {
            $entry1Id = $slot['entry1_id'];
            $entry2Id = $slot['entry2_id'];

            if ($this->gameExistsBetweenEntries($competitionId, $entry1Id, $entry2Id)) {
                continue;
            }

            $created->push(($this->createGame)([
                'competition_id' => $competitionId,
                'group_id' => $group->id,
                'entry1_id' => $entry1Id,
                'entry2_id' => $entry2Id,
                'round' => $round,
                'group_round' => $slot['group_round'],
                'group_match' => $slot['group_match'],
                'best_of' => $matchFormat['best_of'],
                'sets_to_win' => $matchFormat['sets_to_win'],
            ]));
        }

        return $created;
    }

    /**
     * @param  list<int>  $entryIds
     * @return list<array{entry1_id: int, entry2_id: int, group_round: int, group_match: int}>
     */
    private function scheduleSlots(array $entryIds): array
    {
        if (GroupSheetPlayingOrder::supports(count($entryIds))) {
            return $this->officialScheduleSlots($entryIds);
        }

        return $this->bergerScheduleSlots($entryIds);
    }

    /**
     * @param  list<int>  $entryIds
     * @return list<array{entry1_id: int, entry2_id: int, group_round: int, group_match: int}>
     */
    private function officialScheduleSlots(array $entryIds): array
    {
        $entryIdBySheetNumber = array_flip(
            GroupSheetNumbering::forCompetitionEntryIds($entryIds),
        );
        $slots = [];

        foreach (GroupSheetPlayingOrder::forSize(count($entryIds)) as $fixture) {
            $slots[] = [
                'entry1_id' => (int) $entryIdBySheetNumber[$fixture->side1],
                'entry2_id' => (int) $entryIdBySheetNumber[$fixture->side2],
                'group_round' => $fixture->groupRound,
                'group_match' => $fixture->groupMatch,
            ];
        }

        return $slots;
    }

    /**
     * @param  list<int>  $entryIds
     * @return list<array{entry1_id: int, entry2_id: int, group_round: int, group_match: int}>
     */
    private function bergerScheduleSlots(array $entryIds): array
    {
        $slots = [];

        foreach ($this->scheduleBuilder->build($entryIds) as $roundIndex => $roundPairings) {
            $groupRound = $roundIndex + 1;

            foreach ($roundPairings as $matchIndex => $pairing) {
                $slots[] = [
                    'entry1_id' => $pairing['entry1_id'],
                    'entry2_id' => $pairing['entry2_id'],
                    'group_round' => $groupRound,
                    'group_match' => $matchIndex + 1,
                ];
            }
        }

        return $slots;
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
