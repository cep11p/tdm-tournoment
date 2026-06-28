<?php

namespace App\Support\Bracket;

use App\Data\Competition\GroupQualifierData;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class GroupKnockoutDrawBuilder
{
    /**
     * @param  Collection<int, GroupQualifierData>  $qualifiers
     * @return array<int, int>
     */
    public function build(Collection $qualifiers, int $qualifiedPerGroup): array
    {
        if ($qualifiedPerGroup !== 2) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    'El draw consciente de grupos solo admite 2 clasificados por grupo en esta fase.',
                ],
            ]);
        }

        $groups = $qualifiers
            ->groupBy(fn (GroupQualifierData $qualifier): int => $qualifier->groupId)
            ->sortBy(
                fn (Collection $groupQualifiers): string => $groupQualifiers->first()->groupName,
            )
            ->values();

        $groupCount = $groups->count();

        if (! $this->isPowerOfTwo($groupCount)) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    sprintf(
                        'El cuadro eliminatorio requiere una cantidad de grupos potencia de 2 (actual: %d).',
                        $groupCount,
                    ),
                ],
            ]);
        }

        $firsts = [];
        $seconds = [];

        foreach ($groups as $groupQualifiers) {
            $byPosition = $groupQualifiers->keyBy(
                fn (GroupQualifierData $qualifier): int => $qualifier->groupPosition,
            );

            if (! isset($byPosition[1])) {
                throw ValidationException::withMessages([
                    'qualified_per_group' => [
                        sprintf(
                            'Falta el 1° clasificado del grupo "%s".',
                            $groupQualifiers->first()->groupName,
                        ),
                    ],
                ]);
            }

            if (! isset($byPosition[2])) {
                throw ValidationException::withMessages([
                    'qualified_per_group' => [
                        sprintf(
                            'Falta el 2° clasificado del grupo "%s".',
                            $groupQualifiers->first()->groupName,
                        ),
                    ],
                ]);
            }

            $firsts[] = $byPosition[1];
            $seconds[] = $byPosition[2];
        }

        if (count($firsts) !== count($seconds)) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    'La cantidad de primeros y segundos clasificados debe ser la misma.',
                ],
            ]);
        }

        $total = $groupCount * 2;

        if (! $this->isPowerOfTwo($total)) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    sprintf(
                        'El cuadro eliminatorio requiere una cantidad total de clasificados potencia de 2 (actual: %d).',
                        $total,
                    ),
                ],
            ]);
        }

        $playerIds = array_fill(0, $total, 0);

        for ($index = 0; $index < $groupCount; $index++) {
            $playerIds[$index] = $firsts[$index]->playerId;
            $playerIds[$total - 1 - $index] = $seconds[$groupCount - 1 - $index]->playerId;
        }

        return $playerIds;
    }

    private function isPowerOfTwo(int $value): bool
    {
        return $value > 0 && ($value & ($value - 1)) === 0;
    }
}
