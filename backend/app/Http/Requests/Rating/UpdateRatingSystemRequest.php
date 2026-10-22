<?php

namespace App\Http\Requests\Rating;

use App\Enums\RatingMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRatingSystemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('rating_systems', 'name')->ignore($this->route('ratingSystem')),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'initial_rating' => ['sometimes', 'required', 'integer', 'min:0', 'max:4294967295'],
            'method' => ['sometimes', 'required', 'string', Rule::in([RatingMethod::DifferenceTable->value])],
            'active' => ['sometimes', 'boolean'],
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
            'name.unique' => 'Ya existe un sistema de rating con ese nombre.',
            'description.string' => 'La descripción no es válida.',
            'initial_rating.required' => 'El rating inicial es obligatorio.',
            'initial_rating.integer' => 'El rating inicial debe ser un número entero.',
            'initial_rating.min' => 'El rating inicial no puede ser negativo.',
            'initial_rating.max' => 'El rating inicial supera el máximo permitido.',
            'method.required' => 'El método de cálculo es obligatorio.',
            'method.in' => 'El método de cálculo no es válido.',
            'active.boolean' => 'El estado del sistema de rating no es válido.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'description' => 'descripción',
            'initial_rating' => 'rating inicial',
            'method' => 'método',
            'active' => 'activo',
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->exists('name')) {
            $payload['name'] = trim((string) $this->input('name'));
        }

        if ($this->exists('description')) {
            $description = trim((string) $this->input('description'));
            $payload['description'] = $description === '' ? null : $description;
        }

        if ($this->exists('method')) {
            $payload['method'] = trim((string) $this->input('method'));
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }
}
