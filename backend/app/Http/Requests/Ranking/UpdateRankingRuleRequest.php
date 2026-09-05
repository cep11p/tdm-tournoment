<?php

namespace App\Http\Requests\Ranking;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRankingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'points' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'result_key' => ['prohibited'],
            'name' => ['prohibited'],
            'source' => ['prohibited'],
            'position' => ['prohibited'],
            'priority' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'points.integer' => 'Los puntos deben ser un número entero.',
            'points.min' => 'Los puntos no pueden ser negativos.',
            'active.boolean' => 'El estado de la regla no es válido.',
            'result_key.prohibited' => 'El resultado deportivo no se puede modificar.',
            'name.prohibited' => 'El nombre no se puede modificar.',
            'source.prohibited' => 'El origen no se puede modificar.',
            'position.prohibited' => 'La posición no se puede modificar.',
            'priority.prohibited' => 'La prioridad no se puede modificar.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'points' => 'puntos',
            'active' => 'activa',
        ];
    }
}
