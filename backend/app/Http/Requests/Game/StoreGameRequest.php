<?php

namespace App\Http\Requests\Game;

use App\Rules\Registration\PlayerIsRegisteredInCompetitionRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var \App\Models\Competition|null $competition */
        $competition = $this->route('competition');

        return [
            'competition_id' => ['required', 'integer', 'exists:competitions,id'],
            'player1_id' => [
                'required',
                'integer',
                'exists:players,id',
                'different:player2_id',
                new PlayerIsRegisteredInCompetitionRule($competition),
            ],
            'player2_id' => [
                'required',
                'integer',
                'exists:players,id',
                new PlayerIsRegisteredInCompetitionRule($competition),
            ],
            'round' => ['nullable', 'string', 'max:255'],
            'table_number' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $competition = $this->route('competition');

        if ($competition !== null) {
            $this->merge([
                'competition_id' => $competition->getKey(),
            ]);
        }
    }
}
