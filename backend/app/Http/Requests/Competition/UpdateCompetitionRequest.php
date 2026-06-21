<?php

namespace App\Http\Requests\Competition;

use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use App\Models\Competition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCompetitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(CompetitionType::class)],
            'format' => ['sometimes', Rule::enum(CompetitionFormat::class)],
            'sets_to_win' => ['sometimes', 'integer', 'min:1'],
            'points_per_set' => ['sometimes', 'integer', 'min:1'],
            'qualified_per_group' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('qualified_per_group')) {
                return;
            }

            $competition = $this->route('competition');

            if (! $competition instanceof Competition) {
                return;
            }

            if (! $competition->brackets()->exists()) {
                return;
            }

            $newValue = (int) $this->input('qualified_per_group');
            $currentValue = (int) $competition->qualified_per_group;

            if ($newValue !== $currentValue) {
                $validator->errors()->add(
                    'qualified_per_group',
                    'No se puede cambiar la cantidad de clasificados por grupo porque ya existe un cuadro eliminatorio.'
                );
            }
        });
    }
}
