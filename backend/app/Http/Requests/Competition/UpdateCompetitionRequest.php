<?php

namespace App\Http\Requests\Competition;

use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Support\Competition\CompetitionStructureGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCompetitionRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const STRUCTURAL_FIELDS = [
        'type',
        'format',
        'points_per_set',
        'qualified_per_group',
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

            if (CompetitionStructureGuard::isStructureEditable($competition)) {
                return;
            }

            foreach (self::STRUCTURAL_FIELDS as $field) {
                if (! $this->has($field)) {
                    continue;
                }

                if (! $this->structuralFieldChanged($competition, $field)) {
                    continue;
                }

                $validator->errors()->add(
                    $field,
                    CompetitionStructureGuard::LOCK_MESSAGE,
                );
            }
        });
    }

    private function structuralFieldChanged(Competition $competition, string $field): bool
    {
        if ($field === 'format') {
            $newFormat = CompetitionFormat::from((string) $this->input('format'));

            return $newFormat->normalized() !== $competition->format->normalized();
        }

        if ($field === 'type') {
            $newType = CompetitionType::from((string) $this->input('type'));
            $currentType = $competition->type instanceof CompetitionType
                ? $competition->type
                : CompetitionType::from((string) $competition->type);

            return $newType !== $currentType;
        }

        return (int) $this->input($field) !== (int) $competition->{$field};
    }
}
