<?php

namespace App\Http\Requests\Ranking;

use App\Enums\CompetitionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRankingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'competition_type' => ['sometimes', 'required', 'string', Rule::in([
                CompetitionType::Singles->value,
                CompetitionType::Doubles->value,
            ])],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')],
            'season' => ['sometimes', 'required', 'string', 'max:50'],
            'active' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => [
                'sometimes',
                'nullable',
                'date',
                Rule::when($this->filled('starts_at') && $this->filled('ends_at'), ['after_or_equal:starts_at']),
            ],
        ];
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
            'category_id.exists' => 'La categoría seleccionada no existe.',
            'season.required' => 'La temporada es obligatoria.',
            'season.string' => 'La temporada no es válida.',
            'season.max' => 'La temporada no puede superar los 50 caracteres.',
            'active.boolean' => 'El estado del ranking no es válido.',
            'starts_at.date' => 'La vigencia desde no es una fecha válida.',
            'ends_at.date' => 'La vigencia hasta no es una fecha válida.',
            'ends_at.after_or_equal' => 'La vigencia hasta debe ser igual o posterior a la vigencia desde.',
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
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->exists('name')) {
            $payload['name'] = trim((string) $this->input('name'));
        }

        if ($this->exists('season')) {
            $payload['season'] = trim((string) $this->input('season'));
        }

        if ($this->exists('category_id') && $this->input('category_id') === '') {
            $payload['category_id'] = null;
        }

        if ($this->exists('starts_at') && $this->input('starts_at') === '') {
            $payload['starts_at'] = null;
        }

        if ($this->exists('ends_at') && $this->input('ends_at') === '') {
            $payload['ends_at'] = null;
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }
}
