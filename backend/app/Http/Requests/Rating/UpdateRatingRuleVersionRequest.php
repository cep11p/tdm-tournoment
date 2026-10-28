<?php

namespace App\Http\Requests\Rating;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRatingRuleVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rules' => ['sometimes', 'array', $this->listRule()],
            'rules.*' => ['array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rules.array' => 'La tabla de diferencias no es válida.',
            'rules.*.array' => 'Cada rango debe ser un conjunto de valores.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rules' => 'tabla de diferencias',
        ];
    }

    private function listRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_array($value) || ! array_is_list($value)) {
                $fail('La tabla de diferencias no es válida.');
            }
        };
    }
}
