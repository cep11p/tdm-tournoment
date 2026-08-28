<?php

namespace App\Http\Requests\Registration;

use App\Enums\CompetitionType;
use App\Models\Competition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Competition|null $competition */
        $competition = $this->route('competition');

        $type = $competition?->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::Singles;

        $baseRules = [
            'competition_id' => ['required', 'integer', 'exists:competitions,id'],
        ];

        if ($type === CompetitionType::Doubles) {
            return array_merge($baseRules, [
                'player_ids' => ['required', 'array', 'size:2'],
                'player_ids.*' => [
                    'required',
                    'integer',
                    'distinct',
                    Rule::exists('players', 'id')->where('active', true),
                ],
                'player_id' => ['prohibited'],
            ]);
        }

        return array_merge($baseRules, [
            'player_id' => [
                'required',
                'integer',
                Rule::exists('players', 'id')->where('active', true),
            ],
            'player_ids' => ['prohibited'],
        ]);
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
