<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;

class CorrectFinishedGameResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'sets' => ['required', 'array', 'min:1'],
            'sets.*.player1_score' => ['required', 'integer', 'min:0'],
            'sets.*.player2_score' => ['required', 'integer', 'min:0'],
        ];
    }
}
