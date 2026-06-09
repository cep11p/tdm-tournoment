<?php

namespace App\Actions\Competition;

use App\Models\Competition;

final class CreateCompetitionAction
{
    public function __invoke(array $payload): Competition
    {
        return Competition::query()->create($payload);
    }
}
