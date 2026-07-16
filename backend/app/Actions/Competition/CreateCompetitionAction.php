<?php

namespace App\Actions\Competition;

use App\Models\Competition;
use App\Support\Competition\CompetitionCategorySync;

final class CreateCompetitionAction
{
    public function __invoke(array $payload): Competition
    {
        unset($payload['sets_to_win']);

        $payload = CompetitionCategorySync::apply($payload);

        $groupStageBestOf = (int) ($payload['group_stage_best_of'] ?? 5);
        $payload['sets_to_win'] = intdiv($groupStageBestOf, 2) + 1;

        return Competition::query()->create($payload);
    }
}
