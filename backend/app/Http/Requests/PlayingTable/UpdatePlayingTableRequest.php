<?php

namespace App\Http\Requests\PlayingTable;

use App\Actions\PlayingTable\CreatePlayingTableAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlayingTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tournament = $this->route('tournament');
        $playingTable = $this->route('playingTable');

        return [
            'number' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:999',
                Rule::unique('playing_tables', 'number')
                    ->where(fn ($query) => $query->where('tournament_id', $tournament->id))
                    ->ignore($playingTable),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'number.required' => 'El número de mesa es obligatorio.',
            'number.integer' => 'El número de mesa debe ser un número entero.',
            'number.min' => 'El número de mesa debe ser al menos 1.',
            'number.max' => 'El número de mesa no puede ser mayor a 999.',
            'number.unique' => CreatePlayingTableAction::DUPLICATE_NUMBER_MESSAGE,
            'name.string' => 'El nombre debe ser texto.',
            'name.max' => 'El nombre no puede superar los 255 caracteres.',
            'active.boolean' => 'El estado de disponibilidad no es válido.',
            'sort_order.integer' => 'El orden debe ser un número entero.',
            'sort_order.min' => 'El orden no puede ser negativo.',
            'sort_order.max' => 'El orden no puede ser mayor a 9999.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'number' => 'número de mesa',
            'name' => 'nombre',
            'active' => 'disponible para asignaciones',
            'sort_order' => 'orden',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('name')) {
            return;
        }

        $name = trim((string) $this->input('name'));

        $this->merge([
            'name' => $name === '' ? null : $name,
        ]);
    }
}
