<?php

namespace App\Actions\Competition;

use App\Models\Competition;
use App\Support\Competition\CompetitionCategorySync;

final class UpdateCompetitionAction
{
    public function __invoke(Competition $competition, array $payload): Competition
    {
        unset($payload['sets_to_win']);

        $payload = CompetitionCategorySync::apply($payload, $competition);

        $competition->fill($payload);
        $competition->save();

        return $competition->refresh();
    }
}
