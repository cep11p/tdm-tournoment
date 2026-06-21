<?php

namespace App\Actions\Competition;

use App\Models\Competition;

final class UpdateCompetitionAction
{
    public function __invoke(Competition $competition, array $payload): Competition
    {
        $competition->fill($payload);
        $competition->save();

        return $competition->refresh();
    }
}
