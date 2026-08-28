<?php

namespace App\Http\Requests\Registration;

use App\Enums\CompetitionType;
use App\Models\Competition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkStoreRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'player_ids' => ['required', 'array', 'min:1'],
            'player_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('players', 'id')->where('active', true),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Competition|null $competition */
            $competition = $this->route('competition');

            if ($competition === null) {
                return;
            }

            $type = $competition->type instanceof CompetitionType
                ? $competition->type
                : CompetitionType::from((string) $competition->type);

            if ($type === CompetitionType::Doubles) {
                $validator->errors()->add(
                    'player_ids',
                    'El registro masivo de parejas todavía no está disponible.',
                );
            }
        });
    }
}
