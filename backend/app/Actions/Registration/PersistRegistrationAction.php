<?php

namespace App\Actions\Registration;

use App\Models\Competition;
use App\Models\Registration;
use App\Support\Competition\RegistrationGuard;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PersistRegistrationAction
{
    public function __construct(
        private readonly CreateCompetitionEntryForRegistrationAction $createCompetitionEntry,
    ) {}

    public function __invoke(array $payload): Registration
    {
        $competition = Competition::query()->findOrFail($payload['competition_id']);
        $competition->loadMissing('tournament');
        TournamentLifecycleGuard::ensureMutableForCompetition($competition);
        RegistrationGuard::ensureEditable($competition);

        return DB::transaction(function () use ($competition, $payload): Registration {
            try {
                ($this->createCompetitionEntry)($competition, (int) $payload['player_id']);

                return Registration::query()->create($payload);
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
