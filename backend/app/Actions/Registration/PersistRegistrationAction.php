<?php

namespace App\Actions\Registration;

use App\Models\Competition;
use App\Models\Registration;
use App\Support\Competition\RegistrationGuard;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class PersistRegistrationAction
{
    public function __invoke(array $payload): Registration
    {
        $competition = Competition::query()->findOrFail($payload['competition_id']);
        RegistrationGuard::ensureEditable($competition);

        try {
            return Registration::query()->create($payload);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'player_id' => ['El jugador ya está inscripto en esta competencia.'],
                ]);
            }

            throw $exception;
        }
    }
}
