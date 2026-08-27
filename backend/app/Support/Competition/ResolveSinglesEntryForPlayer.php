<?php

namespace App\Support\Competition;

use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use Illuminate\Validation\ValidationException;

final class ResolveSinglesEntryForPlayer
{
    public function __invoke(Competition $competition, int $playerId): CompetitionEntry
    {
        $member = CompetitionEntryMember::query()
            ->where('competition_id', $competition->id)
            ->where('player_id', $playerId)
            ->with('competitionEntry')
            ->first();

        if ($member === null || $member->competitionEntry === null) {
            throw ValidationException::withMessages([
                'player_id' => ['El jugador debe estar inscripto en la competencia.'],
            ]);
        }

        $entry = $member->competitionEntry;

        if ((int) $entry->competition_id !== (int) $competition->id) {
            throw ValidationException::withMessages([
                'player_id' => ['La participación no pertenece a esta competencia.'],
            ]);
        }

        return $entry;
    }
}
