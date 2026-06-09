<?php

namespace App\Actions\Registration;

use App\Models\Registration;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class RegisterPlayerToCompetitionAction
{
    public function __invoke(array $payload): Registration
    {
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
