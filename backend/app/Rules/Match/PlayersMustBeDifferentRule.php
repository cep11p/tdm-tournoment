<?php

namespace App\Rules\Match;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PlayersMustBeDifferentRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Implementar en la siguiente etapa.
    }
}
