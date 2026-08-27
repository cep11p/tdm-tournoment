<?php

namespace App\Rules\CompetitionEntry;

use App\Models\Competition;
use App\Models\CompetitionEntryMember;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PlayerHasCompetitionEntryRule implements ValidationRule
{
    public function __construct(
        private readonly ?Competition $competition
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->competition === null) {
            return;
        }

        $hasEntry = CompetitionEntryMember::query()
            ->where('competition_id', $this->competition->id)
            ->where('player_id', $value)
            ->exists();

        if (! $hasEntry) {
            $fail('El jugador debe estar inscripto en la competencia.');
        }
    }
}
