<?php

namespace App\Actions\CompetitionEntry;

use App\Enums\CompetitionEntryStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Support\Competition\CompetitionEntryGuard;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PersistCompetitionEntryAction
{
    public function __invoke(array $payload): CompetitionEntry
    {
        $competition = Competition::query()->findOrFail($payload['competition_id']);
        $competition->loadMissing('tournament');
        TournamentLifecycleGuard::ensureMutableForCompetition($competition);
        CompetitionEntryGuard::ensureEditable($competition);

        $playerId = (int) $payload['player_id'];

        return DB::transaction(function () use ($competition, $playerId): CompetitionEntry {
            try {
                $entry = CompetitionEntry::query()->create([
                    'competition_id' => $competition->id,
                    'status' => CompetitionEntryStatus::Active,
                ]);

                CompetitionEntryMember::query()->create([
                    'competition_entry_id' => $entry->id,
                    'competition_id' => $competition->id,
                    'player_id' => $playerId,
                    'member_order' => 1,
                ]);

                return $entry;
            } catch (QueryException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    throw ValidationException::withMessages([
                        'player_id' => ['El jugador ya está inscripto en esta competencia.'],
                    ]);
                }

                throw $exception;
            }
        });
    }
}
