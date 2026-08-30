<?php

namespace App\Http\Requests\Competition;

use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use App\Enums\ThirdPlaceMode;
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
        'team_size',
        'format',
        'points_per_set',
        'qualified_per_group',
        'group_stage_best_of',
        'knockout_stage_best_of',
        'semifinal_best_of',
        'final_best_of',
        'third_place_mode',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')->where('active', true)],
            'category' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(CompetitionType::class)],
            'team_size' => ['sometimes', 'nullable', 'integer', 'min:'.Competition::TEAM_SIZE_MIN, 'max:'.Competition::TEAM_SIZE_MAX],
            'format' => ['sometimes', Rule::enum(CompetitionFormat::class)],
            'points_per_set' => ['sometimes', 'integer', 'min:1'],
            'qualified_per_group' => ['sometimes', 'integer', 'min:1'],
            'group_stage_best_of' => ['sometimes', 'integer', Rule::in([1, 3, 5, 7])],
            'knockout_stage_best_of' => ['sometimes', 'integer', Rule::in([1, 3, 5, 7])],
            'semifinal_best_of' => ['sometimes', 'integer', Rule::in([1, 3, 5, 7])],
            'final_best_of' => ['sometimes', 'integer', Rule::in([1, 3, 5, 7])],
            'third_place_mode' => ['sometimes', Rule::enum(ThirdPlaceMode::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if (! $this->filled('category_id') && $this->filled('category')) {
            $slug = mb_strtolower(trim((string) $this->input('category')));
            $categoryId = \App\Models\Category::query()->where('slug', $slug)->value('id');

            if ($categoryId !== null) {
                $payload['category_id'] = $categoryId;
            }
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $competition = $this->route('competition');

            if ($competition instanceof Competition) {
                $this->validateTeamSizeForType($validator, $competition);
                $this->validateStructuralFieldsWhenLocked($validator, $competition);
            }
        });
    }

    private function validateTeamSizeForType(Validator $validator, Competition $competition): void
    {
        if (! $this->has('type') && ! $this->has('team_size')) {
            return;
        }

        $type = $this->has('type')
            ? CompetitionType::tryFrom((string) $this->input('type'))
            : ($competition->type instanceof CompetitionType
                ? $competition->type
                : CompetitionType::from((string) $competition->type));

        if ($type === null) {
            return;
        }

        $teamSize = $this->has('team_size')
            ? $this->input('team_size')
            : $competition->team_size;

        if ($type === CompetitionType::Team) {
            if ($teamSize === null || $teamSize === '') {
                $validator->errors()->add('team_size', 'El tamaño del equipo es obligatorio para competencias por equipos.');
            }

            return;
        }

        if ($this->has('team_size') && $this->input('team_size') !== null && $this->input('team_size') !== '') {
            $validator->errors()->add('team_size', 'El tamaño del equipo solo aplica a competencias por equipos.');
        }
    }

    private function validateStructuralFieldsWhenLocked(Validator $validator, Competition $competition): void
    {
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

        if ($field === 'third_place_mode') {
            $newMode = ThirdPlaceMode::from((string) $this->input('third_place_mode'));
            $currentMode = $competition->third_place_mode instanceof ThirdPlaceMode
                ? $competition->third_place_mode
                : ThirdPlaceMode::from((string) $competition->third_place_mode);

            return $newMode !== $currentMode;
        }

        return (int) $this->input($field) !== (int) $competition->{$field};
    }
}
