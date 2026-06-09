<?php

namespace App\Rules\Registration;

use App\Models\Competition;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PlayerIsRegisteredInCompetitionRule implements ValidationRule
{
    public function __construct(
        private readonly ?Competition $competition
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->competition === null) {
            return;
        }

        $isRegistered = $this->competition->registrations()
            ->where('player_id', $value)
            ->exists();

        if (! $isRegistered) {
            $fail('El jugador debe estar inscripto en la competencia.');
        }
    }
}
