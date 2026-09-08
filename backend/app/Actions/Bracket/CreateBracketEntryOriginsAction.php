<?php

namespace App\Actions\Bracket;

use App\Data\Competition\GroupQualifierData;
use App\Models\Bracket;
use App\Models\BracketEntryOrigin;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CreateBracketEntryOriginsAction
{
    /**
     * @param  Collection<int, GroupQualifierData>  $qualifiers
     * @param  array<int, int>  $entryIds
     */
    public function __invoke(Bracket $bracket, Collection $qualifiers, array $entryIds): void
    {
        $uniqueEntryIds = [];

        foreach ($entryIds as $entryId) {
            $id = (int) $entryId;

            if ($id > 0) {
                $uniqueEntryIds[$id] = $id;
            }
        }

        $uniqueEntryIds = array_values($uniqueEntryIds);

        if ($uniqueEntryIds === []) {
            throw ValidationException::withMessages([
                'bracket' => ['No se puede persistir el origen grupal: el cuadro no tiene participaciones.'],
            ]);
        }

        $qualifiersByEntryId = $qualifiers->keyBy(
            fn (GroupQualifierData $qualifier): int => $qualifier->competitionEntryId,
        );

        $now = now();
        $payload = [];

        foreach ($uniqueEntryIds as $entryId) {
            $qualifier = $qualifiersByEntryId->get($entryId);

            if (! $qualifier instanceof GroupQualifierData) {
                throw ValidationException::withMessages([
                    'bracket' => [
                        sprintf(
                            'No se puede persistir el origen grupal: la inscripción %d no está entre los clasificados.',
                            $entryId,
                        ),
                    ],
                ]);
            }

            $payload[] = [
                'bracket_id' => $bracket->id,
                'competition_id' => $bracket->competition_id,
                'competition_entry_id' => $qualifier->competitionEntryId,
                'group_id' => $qualifier->groupId,
                'group_name' => $qualifier->groupName,
                'group_position' => $qualifier->groupPosition,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        BracketEntryOrigin::query()->insert($payload);
    }
}
