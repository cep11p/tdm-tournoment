<?php

namespace App\Http\Requests\Player;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $player = $this->route('player');

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'nickname' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('players', 'nickname')->ignore($player),
            ],
            'active' => ['sometimes', 'boolean'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'club_id' => ['nullable', 'integer', Rule::exists('clubs', 'id')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->has('first_name')) {
            $payload['first_name'] = trim((string) $this->input('first_name'));
        }

        if ($this->has('last_name')) {
            $payload['last_name'] = trim((string) $this->input('last_name'));
        }

        if ($this->has('nickname')) {
            $nickname = trim((string) $this->input('nickname'));
            $payload['nickname'] = $nickname === '' ? null : $nickname;
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }
}
