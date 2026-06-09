<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreGameSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'set_number' => ['required', 'integer', 'min:1'],
            'player1_score' => ['required', 'integer', 'min:0'],
            'player2_score' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $player1Score = $this->input('player1_score');
            $player2Score = $this->input('player2_score');

            if ($player1Score === null || $player2Score === null) {
                return;
            }

            if ((int) $player1Score === (int) $player2Score) {
                $validator->errors()->add(
                    'player1_score',
                    'Un set no puede finalizar empatado.'
                );
            }
        });
    }
}
