<?php

namespace App\Support\Bracket;

use App\Data\Bracket\BracketDrawMatchData;
use App\Data\Bracket\GroupKnockoutDrawResult;
use App\Data\Bracket\GroupKnockoutDrawTemplate;
use App\Data\Bracket\GroupKnockoutTemplateSlot;
use App\Data\Competition\GroupQualifierData;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class GroupKnockoutDrawTemplateResolver
{
    /**
     * Resuelve una plantilla abstracta (groupIndex, groupPosition) contra
     * clasificados reales. Conserva bracketSize, byesCount y bracketMatch
     * de la plantilla; no recalcula la distribución.
     *
     * El groupIndex se interpreta contra GroupQualifierCanonicalOrder.
     *
     * @param  Collection<int, GroupQualifierData>  $qualifiers
     */
    public function resolve(
        GroupKnockoutDrawTemplate $template,
        Collection $qualifiers,
    ): GroupKnockoutDrawResult {
        $groups = GroupQualifierCanonicalOrder::groups($qualifiers);

        if ($groups->count() !== $template->groupCount) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    sprintf(
                        'No se puede generar la llave: la plantilla requiere %d grupos y hay %d.',
                        $template->groupCount,
                        $groups->count(),
                    ),
                ],
            ]);
        }

        $usedEntryIds = [];
        $matches = [];

        foreach ($template->matches as $match) {
            $entry1Id = $this->resolveSlot($match->side1, $groups, $usedEntryIds);
            $entry2Id = $match->side2 === null
                ? null
                : $this->resolveSlot($match->side2, $groups, $usedEntryIds);

            $matches[] = new BracketDrawMatchData(
                bracketMatch: $match->bracketMatch,
                entry1Id: $entry1Id,
                entry2Id: $entry2Id,
                isBye: $entry2Id === null,
            );
        }

        return new GroupKnockoutDrawResult(
            bracketSize: $template->bracketSize,
            byesCount: $template->byesCount(),
            firstRoundLabel: BracketSupport::roundLabelFor($template->bracketSize),
            matches: $matches,
        );
    }

    /**
     * @param  Collection<int, Collection<int, GroupQualifierData>>  $groups
     * @param  array<int, true>  $usedEntryIds
     */
    private function resolveSlot(
        GroupKnockoutTemplateSlot $slot,
        Collection $groups,
        array &$usedEntryIds,
    ): int {
        $groupQualifiers = $groups->get($slot->groupIndex);

        if ($groupQualifiers === null) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    sprintf(
                        'No se puede generar la llave: la plantilla referencia el grupo en índice %d, que no existe.',
                        $slot->groupIndex,
                    ),
                ],
            ]);
        }

        $qualifier = $groupQualifiers->first(
            fn (GroupQualifierData $candidate): bool => $candidate->groupPosition === $slot->groupPosition,
        );

        if ($qualifier === null) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    sprintf(
                        'No se puede generar la llave: %s no dispone del %d.º clasificado requerido por la plantilla.',
                        $groupQualifiers->first()->groupName,
                        $slot->groupPosition,
                    ),
                ],
            ]);
        }

        $entryId = $qualifier->competitionEntryId;

        if (isset($usedEntryIds[$entryId])) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    sprintf(
                        'No se puede generar la llave: la inscripción %d aparece más de una vez en la resolución.',
                        $entryId,
                    ),
                ],
            ]);
        }

        $usedEntryIds[$entryId] = true;

        return $entryId;
    }
}
