<?php

namespace App\Support\TeamTie;

use App\Models\TeamTieFormat;
use Illuminate\Validation\ValidationException;

final class TeamTieFormatValidator
{
    public static function ensureValid(TeamTieFormat $format): void
    {
        $format->loadMissing('slots');

        $slotsCount = $format->slots->count();

        if ($slotsCount < 1) {
            throw ValidationException::withMessages([
                'team_tie_format' => ['El formato de enfrentamiento debe tener al menos un partido configurado.'],
            ]);
        }

        $victoriesRequired = (int) $format->victories_required;

        if ($victoriesRequired < 1) {
            throw ValidationException::withMessages([
                'team_tie_format' => ['El formato debe requerir al menos una victoria.'],
            ]);
        }

        if ($victoriesRequired > $slotsCount) {
            throw ValidationException::withMessages([
                'team_tie_format' => ['Las victorias requeridas no pueden superar la cantidad de partidos del formato.'],
            ]);
        }

        if ($victoriesRequired <= intdiv($slotsCount, 2)) {
            throw ValidationException::withMessages([
                'team_tie_format' => ['Las victorias requeridas deben ser una mayoría posible del formato.'],
            ]);
        }
    }
}
