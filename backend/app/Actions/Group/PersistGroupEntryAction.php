<?php

namespace App\Actions\Group;

use App\Enums\GroupPlayerStatus;
use App\Models\CompetitionEntry;
use App\Models\Group;
use App\Models\GroupEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PersistGroupEntryAction
{
    public function __invoke(Group $group, CompetitionEntry $entry): GroupEntry
    {
        $group->loadMissing('competition');

        return DB::transaction(function () use ($group, $entry): GroupEntry {
            $this->ensureEntryAssignable($group, $entry);

            try {
                return GroupEntry::query()->create([
                    'group_id' => $group->id,
                    'competition_id' => $group->competition_id,
                    'competition_entry_id' => $entry->id,
                    'status' => GroupPlayerStatus::Active,
                ]);
            } catch (QueryException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    throw $this->translateUniqueViolation($group, $entry);
                }

                throw $exception;
            }
        });
    }

    /**
     * @param  Collection<int, array{group: Group, entry: CompetitionEntry}>  $assignments
     */
    public function insertMany(Collection $assignments): void
    {
        if ($assignments->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($assignments): void {
            $entryIds = $assignments
                ->map(fn (array $assignment): int => (int) $assignment['entry']->id)
                ->unique()
                ->values();

            $entries = CompetitionEntry::query()
                ->whereIn('id', $entryIds)
                ->with('members')
                ->get()
                ->keyBy('id');

            foreach ($assignments as $assignment) {
                /** @var Group $group */
                $group = $assignment['group'];
                /** @var CompetitionEntry $entry */
                $entry = $assignment['entry'];

                $group->loadMissing('competition');
                $this->ensureEntryAssignable($group, $entry);
            }

            $now = now();
            $groupEntriesPayload = [];

            foreach ($assignments as $assignment) {
                /** @var Group $group */
                $group = $assignment['group'];
                /** @var CompetitionEntry $assignmentEntry */
                $assignmentEntry = $assignment['entry'];
                $entry = $entries->get($assignmentEntry->id) ?? $assignmentEntry;

                $groupEntriesPayload[] = [
                    'group_id' => $group->id,
                    'competition_id' => $group->competition_id,
                    'competition_entry_id' => $entry->id,
                    'status' => GroupPlayerStatus::Active->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            try {
                GroupEntry::query()->insert($groupEntriesPayload);
            } catch (QueryException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    throw ValidationException::withMessages([
                        'competition' => ['No se pudo asignar una o más participaciones a los grupos.'],
                    ]);
                }

                throw $exception;
            }
        });
    }

    private function ensureEntryAssignable(Group $group, CompetitionEntry $entry): void
    {
        if ((int) $entry->competition_id !== (int) $group->competition_id) {
            throw ValidationException::withMessages([
                'competition_entry_id' => ['La participación no pertenece a esta competencia.'],
            ]);
        }

        $alreadyAssignedInCompetition = GroupEntry::query()
            ->where('competition_id', $group->competition_id)
            ->where('competition_entry_id', $entry->id)
            ->exists();

        if ($alreadyAssignedInCompetition) {
            $alreadyInSameGroup = GroupEntry::query()
                ->where('group_id', $group->id)
                ->where('competition_entry_id', $entry->id)
                ->exists();

            throw ValidationException::withMessages([
                'player_id' => [
                    $alreadyInSameGroup
                        ? 'El jugador ya está asignado a este grupo.'
                        : 'El jugador ya está asignado a un grupo de esta competencia.',
                ],
            ]);
        }
    }

    private function translateUniqueViolation(Group $group, CompetitionEntry $entry): ValidationException
    {
        $alreadyInSameGroup = GroupEntry::query()
            ->where('group_id', $group->id)
            ->where('competition_entry_id', $entry->id)
            ->exists();

        return ValidationException::withMessages([
            'player_id' => [
                $alreadyInSameGroup
                    ? 'El jugador ya está asignado a este grupo.'
                    : 'El jugador ya está asignado a un grupo de esta competencia.',
            ],
        ]);
    }
}
