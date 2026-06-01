<?php

namespace App\Rules\Tournament;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidTournamentStatusTransitionRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Implementar en la siguiente etapa.
    }
}
