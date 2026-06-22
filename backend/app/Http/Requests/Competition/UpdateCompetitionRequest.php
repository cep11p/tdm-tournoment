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
    /**
     * @var array<int, string>
     */
    private const FORMAT_FIELDS = [
        'group_stage_best_of',
        'knockout_stage_best_of',
        'semifinal_best_of',
        'final_best_of',
    ];

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
            'points_per_set' => ['sometimes', 'integer', 'min:1'],
            'qualified_per_group' => ['sometimes', 'integer', 'min:1'],
            'group_stage_best_of' => ['sometimes', 'integer', Rule::in([1, 3, 5, 7])],
            'knockout_stage_best_of' => ['sometimes', 'integer', Rule::in([1, 3, 5, 7])],
            'semifinal_best_of' => ['sometimes', 'integer', Rule::in([1, 3, 5, 7])],
            'final_best_of' => ['sometimes', 'integer', Rule::in([1, 3, 5, 7])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $competition = $this->route('competition');

            if (! $competition instanceof Competition) {
                return;
            }

            if ($this->has('qualified_per_group')) {
                if ($competition->brackets()->exists()) {
                    $newValue = (int) $this->input('qualified_per_group');
                    $currentValue = (int) $competition->qualified_per_group;

                    if ($newValue !== $currentValue) {
                        $validator->errors()->add(
                            'qualified_per_group',
                            'No se puede cambiar la cantidad de clasificados por grupo porque ya existe un cuadro eliminatorio.'
                        );
                    }
                }
            }

            if (! $competition->games()->exists()) {
                return;
            }

            foreach (self::FORMAT_FIELDS as $field) {
                if (! $this->has($field)) {
                    continue;
                }

                $newValue = (int) $this->input($field);
                $currentValue = (int) $competition->{$field};

                if ($newValue !== $currentValue) {
                    $validator->errors()->add(
                        $field,
                        'No se puede cambiar el formato de sets porque ya existen partidos generados.'
                    );
                }
            }
        });
    }
}
