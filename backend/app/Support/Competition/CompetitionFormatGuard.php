<?php

namespace App\Support\Competition;

use App\Models\Competition;
use Illuminate\Validation\ValidationException;

final class CompetitionFormatGuard
{
    public static function ensureGroupStage(Competition $competition): void
    {
        if (! $competition->format->hasGroupStage()) {
            throw ValidationException::withMessages([
                'competition' => ['Esta competencia no utiliza fase de grupos.'],
            ]);
        }
    }
}
