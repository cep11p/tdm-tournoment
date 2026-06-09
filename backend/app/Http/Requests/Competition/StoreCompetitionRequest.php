<?php

namespace App\Http\Requests\Competition;

use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompetitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tournament_id' => ['required', 'integer', 'exists:tournaments,id'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CompetitionType::class)],
            'format' => ['required', Rule::enum(CompetitionFormat::class)],
            'sets_to_win' => ['required', 'integer', 'min:1'],
            'points_per_set' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $tournament = $this->route('tournament');

        if ($tournament !== null) {
            $this->merge([
                'tournament_id' => $tournament->getKey(),
            ]);
        }
    }
}
