<?php

namespace App\Actions\Tournament;

use App\Models\Tournament;

final class UpdateTournamentAction
{
    public function __invoke(Tournament $tournament, array $payload): Tournament
    {
        $tournament->fill($payload);
        $tournament->save();

        return $tournament->refresh();
    }
}
