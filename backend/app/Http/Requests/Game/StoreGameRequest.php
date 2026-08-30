<?php

namespace App\Http\Requests\Game;

use App\Enums\CompetitionType;
use App\Rules\CompetitionEntry\PlayerHasCompetitionEntryRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
        $type = $competition?->type instanceof CompetitionType
            ? $competition->type
            : null;

        if ($type?->isMultiMember() === true) {
            return [
                'competition_id' => ['required', 'integer', 'exists:competitions,id'],
                'entry1_id' => [
                    'required',
                    'integer',
                    'exists:competition_entries,id',
                    'different:entry2_id',
                ],
                'entry2_id' => [
                    'required',
                    'integer',
                    'exists:competition_entries,id',
                ],
                'player1_id' => ['prohibited'],
                'player2_id' => ['prohibited'],
                'round' => ['nullable', 'string', 'max:255'],
                'table_number' => ['nullable', 'integer', 'min:1'],
            ];
        }

        return [
            'competition_id' => ['required', 'integer', 'exists:competitions,id'],
            'player1_id' => [
                'required_without:entry1_id',
                'nullable',
                'integer',
                'exists:players,id',
                'different:player2_id',
                new PlayerHasCompetitionEntryRule($competition),
            ],
            'player2_id' => [
                'required_without:entry2_id',
                'nullable',
                'integer',
                'exists:players,id',
                new PlayerHasCompetitionEntryRule($competition),
            ],
            'entry1_id' => [
                'required_without:player1_id',
                'nullable',
                'integer',
                'exists:competition_entries,id',
                'different:entry2_id',
            ],
            'entry2_id' => [
                'required_without:player2_id',
                'nullable',
                'integer',
                'exists:competition_entries,id',
            ],
            'round' => ['nullable', 'string', 'max:255'],
            'table_number' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var \App\Models\Competition|null $competition */
            $competition = $this->route('competition');

            $type = $competition?->type instanceof CompetitionType
                ? $competition->type
                : null;

            if ($type?->isMultiMember() !== true) {
                return;
            }

            if ($this->filled('player1_id') || $this->filled('player2_id')) {
                $message = $type->isTeam()
                    ? 'No se puede crear un partido por equipos usando player_id.'
                    : 'No se puede crear un partido de dobles usando player_id.';

                $validator->errors()->add('player1_id', $message);
            }
        });
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
