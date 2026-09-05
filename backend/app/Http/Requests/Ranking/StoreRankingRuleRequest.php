<?php

namespace App\Http\Requests\Ranking;

use App\Support\Ranking\RankingRuleCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRankingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'result_key' => ['required', 'string', Rule::in(RankingRuleCatalog::keys())],
            'points' => ['required', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
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
            'result_key.required' => 'El resultado es obligatorio.',
            'result_key.in' => 'El resultado deportivo no es válido.',
            'points.required' => 'Los puntos son obligatorios.',
            'points.integer' => 'Los puntos deben ser un número entero.',
            'points.min' => 'Los puntos no pueden ser negativos.',
            'active.boolean' => 'El estado de la regla no es válido.',
            'name.prohibited' => 'El nombre se deriva del resultado y no se puede enviar.',
            'source.prohibited' => 'El origen se deriva del resultado y no se puede enviar.',
            'position.prohibited' => 'La posición se deriva del resultado y no se puede enviar.',
            'priority.prohibited' => 'La prioridad no se puede enviar.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'result_key' => 'resultado',
            'points' => 'puntos',
            'active' => 'activa',
        ];
    }
}
