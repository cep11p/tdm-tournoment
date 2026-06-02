<?php

namespace App\Actions\Tournament;

use App\Enums\TournamentStatus;
use App\Models\Tournament;

final class CreateTournamentAction
{
    public function __invoke(array $payload): Tournament
    {
        $payload['status'] ??= TournamentStatus::Draft;

        return Tournament::query()->create($payload);
    }
}
