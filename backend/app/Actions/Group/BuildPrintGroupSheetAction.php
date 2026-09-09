<?php

namespace App\Actions\Group;

use App\Data\Group\GroupSheetResolvedGame;
use App\Data\Group\PrintGroupSheetData;
use App\Enums\CompetitionType;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupEntry;
use App\Support\Game\GameFormatResolver;
use App\Support\Group\GroupFixtureOrder;
use App\Support\Group\GroupRefereeAssigner;
use App\Support\Group\GroupSheetFixtureMismatchException;
use App\Support\Group\GroupSheetGameResolver;
use App\Support\Group\GroupSheetMatrix;
use App\Support\Group\GroupSheetNumbering;
use App\Support\Group\GroupSheetPlayingOrder;
use App\Support\Group\PrintGroupEntryPayload;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class BuildPrintGroupSheetAction
{
    public const TEAM_NOT_AVAILABLE_MESSAGE = 'La impresión de grupos por equipos todavía no está disponible.';

    public const MISSING_FIXTURE_MESSAGE = 'Los partidos del round robin aún no fueron generados.';

    public const INVALID_FIXTURE_MESSAGE = 'Los partidos del grupo no coinciden con el round robin esperado para la planilla.';

    private const GAME_RELATIONS = [
        'entry1.members.player:id,first_name,last_name,nickname',
        'entry2.members.player:id,first_name,last_name,nickname',
    ];

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
                'group' => [self::TEAM_NOT_AVAILABLE_MESSAGE],
            ]);
        }

        $entriesById = $this->entriesById($group);
        $sheetNumberByEntryId = GroupSheetNumbering::forCompetitionEntryIds(
            $entriesById->keys()->map(fn ($id): int => (int) $id)->all(),
        );
        $size = $entriesById->count();
        $usesSheetOrder = GroupSheetPlayingOrder::supports($size);

        $games = $usesSheetOrder
            ? $group->games()->with(self::GAME_RELATIONS)->get()
            : GroupFixtureOrder::games($group, self::GAME_RELATIONS);

        if ($games->isEmpty()) {
            throw ValidationException::withMessages([
                'group' => [self::MISSING_FIXTURE_MESSAGE],
            ]);
        }

        if ($usesSheetOrder) {
            try {
                $resolved = GroupSheetGameResolver::resolve(
                    $size,
                    $entriesById,
                    $sheetNumberByEntryId,
                    $games,
                );
            } catch (GroupSheetFixtureMismatchException) {
                throw ValidationException::withMessages([
                    'group' => [self::INVALID_FIXTURE_MESSAGE],
                ]);
            }

            $orderedGames = collect($resolved)
                ->map(fn (GroupSheetResolvedGame $item): Game => $item->game)
                ->values();
            $refereesByGameId = $this->refereeAssigner->assign($orderedGames);
            $matches = $this->matchesFromResolved(
                $resolved,
                $refereesByGameId,
                $entriesById,
                $sheetNumberByEntryId,
            );
        } else {
            $refereesByGameId = $this->refereeAssigner->assign($games);
            $matches = $this->matchesFromGames(
                $games,
                $refereesByGameId,
                $entriesById,
                $sheetNumberByEntryId,
            );
        }

        $participants = $this->participants($entriesById, $sheetNumberByEntryId);

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
            participants: $participants,
            matches: $matches,
            sheetKind: $usesSheetOrder ? 'g'.$size : 'generic',
            matrix: GroupSheetMatrix::build($participants, $matches),
        );
    }

    /**
     * @return Collection<int, CompetitionEntry>
     */
    private function entriesById(Group $group): Collection
    {
        $group->loadMissing([
            'groupEntries.competitionEntry.members.player:id,first_name,last_name,nickname',
        ]);

        return $group->groupEntries
            ->map(fn (GroupEntry $groupEntry): ?CompetitionEntry => $groupEntry->competitionEntry)
            ->filter()
            ->unique(fn (CompetitionEntry $entry): int => (int) $entry->id)
            ->keyBy(fn (CompetitionEntry $entry): int => (int) $entry->id)
            ->sortKeys();
    }

    /**
     * @param  Collection<int, CompetitionEntry>  $entriesById
     * @param  array<int, int>  $sheetNumberByEntryId
     * @return list<array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}>
     */
    private function participants(Collection $entriesById, array $sheetNumberByEntryId): array
    {
        return $entriesById
            ->sortBy(fn (CompetitionEntry $entry): int => $sheetNumberByEntryId[(int) $entry->id] ?? PHP_INT_MAX)
            ->values()
            ->map(fn (CompetitionEntry $entry): array => PrintGroupEntryPayload::for(
                $entry,
                $sheetNumberByEntryId[(int) $entry->id] ?? null,
            ))
            ->all();
    }

    /**
     * @param  list<GroupSheetResolvedGame>  $resolved
     * @param  array<int, int|null>  $refereesByGameId
     * @param  Collection<int, CompetitionEntry>  $entriesById
     * @param  array<int, int>  $sheetNumberByEntryId
     * @return list<array{
     *     game_id: int,
     *     order: int,
     *     group_round: int|null,
     *     group_match: int|null,
     *     side1_number: int|null,
     *     side1: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     side2_number: int|null,
     *     side2: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     referee_number: int|null,
     *     referee: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     best_of: int|null,
     *     sets_to_win: int|null
     * }>
     */
    private function matchesFromResolved(
        array $resolved,
        array $refereesByGameId,
        Collection $entriesById,
        array $sheetNumberByEntryId,
    ): array {
        $matches = [];
        $order = 1;

        foreach ($resolved as $item) {
            $matches[] = $this->matchPayload(
                game: $item->game,
                order: $order,
                groupRound: $item->fixture->groupRound,
                groupMatch: $item->fixture->groupMatch,
                side1: $item->side1,
                side2: $item->side2,
                refereesByGameId: $refereesByGameId,
                entriesById: $entriesById,
                sheetNumberByEntryId: $sheetNumberByEntryId,
            );

            $order++;
        }

        return $matches;
    }

    /**
     * @param  Collection<int, Game>  $games
     * @param  array<int, int|null>  $refereesByGameId
     * @param  Collection<int, CompetitionEntry>  $entriesById
     * @param  array<int, int>  $sheetNumberByEntryId
     * @return list<array{
     *     game_id: int,
     *     order: int,
     *     group_round: int|null,
     *     group_match: int|null,
     *     side1_number: int|null,
     *     side1: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     side2_number: int|null,
     *     side2: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     referee_number: int|null,
     *     referee: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     best_of: int|null,
     *     sets_to_win: int|null
     * }>
     */
    private function matchesFromGames(
        Collection $games,
        array $refereesByGameId,
        Collection $entriesById,
        array $sheetNumberByEntryId,
    ): array {
        $matches = [];
        $order = 1;

        foreach ($games as $game) {
            $matches[] = $this->matchPayload(
                game: $game,
                order: $order,
                groupRound: $game->group_round !== null ? (int) $game->group_round : null,
                groupMatch: $game->group_match !== null ? (int) $game->group_match : null,
                side1: $game->entry1,
                side2: $game->entry2,
                refereesByGameId: $refereesByGameId,
                entriesById: $entriesById,
                sheetNumberByEntryId: $sheetNumberByEntryId,
            );

            $order++;
        }

        return $matches;
    }

    /**
     * @param  array<int, int|null>  $refereesByGameId
     * @param  Collection<int, CompetitionEntry>  $entriesById
     * @param  array<int, int>  $sheetNumberByEntryId
     * @return array{
     *     game_id: int,
     *     order: int,
     *     group_round: int|null,
     *     group_match: int|null,
     *     side1_number: int|null,
     *     side1: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     side2_number: int|null,
     *     side2: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     referee_number: int|null,
     *     referee: array{competition_entry_id: int, sheet_number: int|null, display_name: string, members: list<array{id: int|null, first_name: string|null, last_name: string|null, nickname: string|null}>}|null,
     *     best_of: int|null,
     *     sets_to_win: int|null
     * }
     */
    private function matchPayload(
        Game $game,
        int $order,
        ?int $groupRound,
        ?int $groupMatch,
        ?CompetitionEntry $side1,
        ?CompetitionEntry $side2,
        array $refereesByGameId,
        Collection $entriesById,
        array $sheetNumberByEntryId,
    ): array {
        $side1Number = $this->sheetNumberFor($side1, $sheetNumberByEntryId);
        $side2Number = $this->sheetNumberFor($side2, $sheetNumberByEntryId);
        $refereeEntryId = $refereesByGameId[(int) $game->id] ?? null;
        $referee = $refereeEntryId !== null
            ? $entriesById->get($refereeEntryId)
            : null;
        $refereeNumber = $this->sheetNumberFor($referee, $sheetNumberByEntryId);

        return [
            'game_id' => (int) $game->id,
            'order' => $order,
            'group_round' => $groupRound,
            'group_match' => $groupMatch,
            'side1_number' => $side1Number,
            'side1' => PrintGroupEntryPayload::for($side1, $side1Number),
            'side2_number' => $side2Number,
            'side2' => PrintGroupEntryPayload::for($side2, $side2Number),
            'referee_number' => $refereeNumber,
            'referee' => PrintGroupEntryPayload::for($referee, $refereeNumber),
            'best_of' => $game->best_of !== null ? (int) $game->best_of : null,
            'sets_to_win' => $game->sets_to_win !== null ? (int) $game->sets_to_win : null,
        ];
    }

    /**
     * @param  array<int, int>  $sheetNumberByEntryId
     */
    private function sheetNumberFor(?CompetitionEntry $entry, array $sheetNumberByEntryId): ?int
    {
        if ($entry === null) {
            return null;
        }

        return $sheetNumberByEntryId[(int) $entry->id] ?? null;
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
