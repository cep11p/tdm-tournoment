<?php

namespace App\Actions\Group;

use App\Data\Group\PrintGroupSheetData;
use App\Enums\CompetitionType;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Models\Group;
use App\Support\Game\GameFormatResolver;
use App\Support\Group\GroupFixtureOrder;
use App\Support\Group\GroupRefereeAssigner;
use App\Support\Group\PrintGroupEntryPayload;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class BuildPrintGroupSheetAction
{
    public function __construct(
        private readonly GroupRefereeAssigner $refereeAssigner,
    ) {}

    public function __invoke(Group $group): PrintGroupSheetData
    {
        $group->loadMissing(['competition.tournament']);

        $type = $group->competition->type instanceof CompetitionType
            ? $group->competition->type
            : CompetitionType::from((string) $group->competition->type);

        if ($type === CompetitionType::Team) {
            throw ValidationException::withMessages([
                'group' => ['La impresión de grupos por equipos todavía no está disponible.'],
            ]);
        }

        $games = GroupFixtureOrder::games($group, [
            'entry1.members.player:id,first_name,last_name,nickname',
            'entry2.members.player:id,first_name,last_name,nickname',
        ]);

        if ($games->isEmpty()) {
            throw ValidationException::withMessages([
                'group' => ['Los partidos del round robin aún no fueron generados.'],
            ]);
        }

        $refereesByGameId = $this->refereeAssigner->assign($games);
        $entriesById = $this->entriesById($games);

        return new PrintGroupSheetData(
            tournament: [
                'id' => (int) $group->competition->tournament_id,
                'name' => (string) ($group->competition->tournament?->name ?? ''),
            ],
            competition: [
                'id' => (int) $group->competition_id,
                'name' => (string) $group->competition->name,
                'type' => $type->value,
            ],
            group: [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
            ],
            bestOf: $this->headerBestOf($games, $group),
            setsToWin: $this->headerSetsToWin($games, $group),
            pointsPerSet: (int) $group->competition->points_per_set,
            qualifiedPerGroup: (int) $group->competition->qualified_per_group,
            participants: $this->participants($entriesById),
            matches: $this->matches($games, $refereesByGameId, $entriesById),
        );
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return Collection<int, CompetitionEntry>
     */
    private function entriesById(Collection $games): Collection
    {
        $entries = collect();

        foreach ($games as $game) {
            if ($game->entry1 !== null) {
                $entries->put((int) $game->entry1->id, $game->entry1);
            }

            if ($game->entry2 !== null) {
                $entries->put((int) $game->entry2->id, $game->entry2);
            }
        }

        return $entries->sortKeys();
    }

    /**
     * @param  Collection<int, CompetitionEntry>  $entriesById
     * @return list<array{competition_entry_id: int, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}>
     */
    private function participants(Collection $entriesById): array
    {
        return $entriesById
            ->values()
            ->map(fn (CompetitionEntry $entry): array => PrintGroupEntryPayload::for($entry))
            ->all();
    }

    /**
     * @param  Collection<int, Game>  $games
     * @param  array<int, int|null>  $refereesByGameId
     * @param  Collection<int, CompetitionEntry>  $entriesById
     * @return list<array{
     *     game_id: int,
     *     order: int,
     *     group_round: int|null,
     *     group_match: int|null,
     *     side1: array{competition_entry_id: int, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     side2: array{competition_entry_id: int, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     referee: array{competition_entry_id: int, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     best_of: int|null,
     *     sets_to_win: int|null
     * }>
     */
    private function matches(
        Collection $games,
        array $refereesByGameId,
        Collection $entriesById,
    ): array {
        $matches = [];
        $order = 1;

        foreach ($games as $game) {
            $refereeEntryId = $refereesByGameId[(int) $game->id] ?? null;
            $referee = $refereeEntryId !== null
                ? PrintGroupEntryPayload::for($entriesById->get($refereeEntryId))
                : null;

            $matches[] = [
                'game_id' => (int) $game->id,
                'order' => $order,
                'group_round' => $game->group_round !== null ? (int) $game->group_round : null,
                'group_match' => $game->group_match !== null ? (int) $game->group_match : null,
                'side1' => PrintGroupEntryPayload::for($game->entry1),
                'side2' => PrintGroupEntryPayload::for($game->entry2),
                'referee' => $referee,
                'best_of' => $game->best_of !== null ? (int) $game->best_of : null,
                'sets_to_win' => $game->sets_to_win !== null ? (int) $game->sets_to_win : null,
            ];

            $order++;
        }

        return $matches;
    }

    /**
     * @param  Collection<int, Game>  $games
     */
    private function headerBestOf(Collection $games, Group $group): int
    {
        $bestOfs = $games
            ->pluck('best_of')
            ->filter(fn ($value): bool => $value !== null)
            ->map(fn ($value): int => (int) $value)
            ->values();

        if ($bestOfs->isEmpty()) {
            return (int) $group->competition->group_stage_best_of;
        }

        return (int) $bestOfs->max();
    }

    /**
     * @param  Collection<int, Game>  $games
     */
    private function headerSetsToWin(Collection $games, Group $group): int
    {
        $bestOf = $this->headerBestOf($games, $group);

        return GameFormatResolver::fromBestOf($bestOf)['sets_to_win'];
    }
}
