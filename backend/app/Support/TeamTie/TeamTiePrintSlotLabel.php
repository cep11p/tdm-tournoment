<?php

namespace App\Support\TeamTie;

use App\Enums\TeamTieModality;

final class TeamTiePrintSlotLabel
{
    /**
     * @param  list<TeamTieModality|string>  $modalities
     * @return list<string>
     */
    public static function forModalities(array $modalities): array
    {
        $normalized = [];

        foreach (array_values($modalities) as $modality) {
            $normalized[] = $modality instanceof TeamTieModality
                ? $modality
                : TeamTieModality::from((string) $modality);
        }

        $singlesTotal = 0;
        $doublesTotal = 0;

        foreach ($normalized as $modality) {
            if ($modality === TeamTieModality::Doubles) {
                $doublesTotal++;
            } else {
                $singlesTotal++;
            }
        }

        $labels = [];
        $singlesIndex = 0;
        $doublesIndex = 0;

        foreach ($normalized as $modality) {
            if ($modality === TeamTieModality::Doubles) {
                $doublesIndex++;
                $labels[] = $doublesTotal === 1
                    ? 'Dobles'
                    : sprintf('Dobles %d', $doublesIndex);

                continue;
            }

            $singlesIndex++;
            $labels[] = $singlesTotal === 1
                ? 'Individual'
                : sprintf('Individual %d', $singlesIndex);
        }

        return $labels;
    }
}
