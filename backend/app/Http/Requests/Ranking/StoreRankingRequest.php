<?php

namespace App\Http\Requests\Ranking;

use App\Actions\Ranking\CopyRankingRulesAction;
use App\Enums\CompetitionType;
use App\Models\Ranking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRankingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'competition_type' => ['required', 'string', Rule::in([
                CompetitionType::Singles->value,
                CompetitionType::Doubles->value,
            ])],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('active', true)],
            'season' => ['required', 'string', 'max:50'],
            'active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => [
                'nullable',
                'date',
                Rule::when($this->filled('starts_at') && $this->filled('ends_at'), ['after_or_equal:starts_at']),
            ],
            'copy_rules_from_ranking_id' => ['nullable', 'integer', 'exists:rankings,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $copyId = $this->input('copy_rules_from_ranking_id');
            $type = (string) $this->input('competition_type');

            if ($copyId === null || $copyId === '' || $type === '') {
                return;
            }

            $source = Ranking::query()->find((int) $copyId);

            if ($source === null) {
                return;
            }

            $sourceType = $source->competition_type instanceof CompetitionType
                ? $source->competition_type->value
                : (string) $source->competition_type;

            if ($sourceType !== $type) {
                $validator->errors()->add(
                    'copy_rules_from_ranking_id',
                    CopyRankingRulesAction::CROSS_TYPE_MESSAGE,
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.string' => 'El nombre no es válido.',
            'name.max' => 'El nombre no puede superar los 255 caracteres.',
            'competition_type.required' => 'La modalidad es obligatoria.',
            'competition_type.in' => 'La modalidad del ranking no es válida.',
            'category_id.integer' => 'La categoría no es válida.',
            'category_id.exists' => 'La categoría seleccionada no existe o no está activa.',
            'season.required' => 'La temporada es obligatoria.',
            'season.string' => 'La temporada no es válida.',
            'season.max' => 'La temporada no puede superar los 50 caracteres.',
            'active.boolean' => 'El estado del ranking no es válido.',
            'starts_at.date' => 'La vigencia desde no es una fecha válida.',
            'ends_at.date' => 'La vigencia hasta no es una fecha válida.',
            'ends_at.after_or_equal' => 'La vigencia hasta debe ser igual o posterior a la vigencia desde.',
            'copy_rules_from_ranking_id.integer' => 'El ranking de origen no es válido.',
            'copy_rules_from_ranking_id.exists' => 'El ranking de origen no existe.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'competition_type' => 'modalidad',
            'category_id' => 'categoría',
            'season' => 'temporada',
            'active' => 'activo',
            'starts_at' => 'vigencia desde',
            'ends_at' => 'vigencia hasta',
            'copy_rules_from_ranking_id' => 'ranking de origen',
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [
            'name' => trim((string) $this->input('name', '')),
            'season' => trim((string) $this->input('season', '')),
        ];

        if ($this->exists('category_id') && $this->input('category_id') === '') {
            $payload['category_id'] = null;
        }

        if ($this->exists('starts_at') && $this->input('starts_at') === '') {
            $payload['starts_at'] = null;
        }

        if ($this->exists('ends_at') && $this->input('ends_at') === '') {
            $payload['ends_at'] = null;
        }

        if ($this->exists('copy_rules_from_ranking_id') && $this->input('copy_rules_from_ranking_id') === '') {
            $payload['copy_rules_from_ranking_id'] = null;
        }

        $this->merge($payload);
    }
}
