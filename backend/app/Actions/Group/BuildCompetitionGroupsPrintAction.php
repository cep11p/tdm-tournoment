<?php

namespace App\Actions\Group;

use App\Data\Group\PrintCompetitionGroupsData;
use App\Data\Group\PrintGroupSheetData;
use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\Group;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class BuildCompetitionGroupsPrintAction
{
    public const WITHOUT_GROUPS_MESSAGE = 'La competencia todavía no tiene grupos generados.';

    public const GROUPS_WITHOUT_SCHEDULE_MESSAGE = 'No se puede imprimir la competencia porque algunos grupos todavía no tienen sus partidos generados.';

    public function __construct(
        private readonly BuildPrintGroupSheetAction $buildPrintGroupSheet,
    ) {}

    public function __invoke(Competition $competition): PrintCompetitionGroupsData
    {
        $competition->loadMissing('tournament');

        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        if ($type === CompetitionType::Team) {
            throw ValidationException::withMessages([
                'competition' => [BuildPrintGroupSheetAction::TEAM_NOT_AVAILABLE_MESSAGE],
            ]);
        }

        $groups = $competition->groups()
            ->orderBy('name')
            ->withCount('games')
            ->get();

        if ($groups->isEmpty()) {
            throw ValidationException::withMessages([
                'competition' => [self::WITHOUT_GROUPS_MESSAGE],
            ]);
        }

        $groupsWithoutSchedule = $groups
            ->filter(fn (Group $group): bool => (int) $group->games_count === 0)
            ->values();

        if ($groupsWithoutSchedule->isNotEmpty()) {
            $this->throwMissingFixtures($groupsWithoutSchedule);
        }

        $sheets = $groups
            ->map(function (Group $group) use ($competition): PrintGroupSheetData {
                $group->setRelation('competition', $competition);

                return ($this->buildPrintGroupSheet)($group);
            })
            ->values()
            ->all();

        return new PrintCompetitionGroupsData(
            tournament: [
                'id' => (int) $competition->tournament_id,
                'name' => (string) ($competition->tournament?->name ?? ''),
            ],
            competition: [
                'id' => (int) $competition->id,
                'name' => (string) $competition->name,
                'type' => $type->value,
            ],
            groupsCount: count($sheets),
            sheets: $sheets,
        );
    }

    /**
     * @param  Collection<int, Group>  $groupsWithoutSchedule
     */
    private function throwMissingFixtures(Collection $groupsWithoutSchedule): never
    {
        $exception = ValidationException::withMessages([
            'competition' => [self::GROUPS_WITHOUT_SCHEDULE_MESSAGE],
        ]);

        $exception->response = response()->json([
            'message' => self::GROUPS_WITHOUT_SCHEDULE_MESSAGE,
            'errors' => $exception->errors(),
            'groups_without_schedule' => $groupsWithoutSchedule
                ->map(fn (Group $group): array => [
                    'id' => (int) $group->id,
                    'name' => (string) $group->name,
                ])
                ->all(),
        ], 422);

        throw $exception;
    }
}
