<?php

namespace App\Rules\Registration;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PlayersMustBeRegisteredInCompetitionRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Implementar en la siguiente etapa.
    }
}
