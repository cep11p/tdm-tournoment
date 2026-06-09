<?php

namespace App\Http\Requests\Registration;

use Illuminate\Foundation\Http\FormRequest;

class StoreRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'competition_id' => ['required', 'integer', 'exists:competitions,id'],
            'player_id' => ['required', 'integer', 'exists:players,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $competition = $this->route('competition');

        if ($competition !== null) {
            $this->merge([
                'competition_id' => $competition->getKey(),
            ]);
        }
    }
}
