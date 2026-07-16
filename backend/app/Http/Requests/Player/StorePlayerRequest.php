<?php

namespace App\Http\Requests\Player;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:255', Rule::unique('players', 'nickname')],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'club_id' => ['nullable', 'integer', Rule::exists('clubs', 'id')],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $nickname = trim((string) $this->input('nickname', ''));

        $this->merge([
            'first_name' => trim((string) $this->input('first_name', '')),
            'last_name' => trim((string) $this->input('last_name', '')),
            'nickname' => $nickname === '' ? null : $nickname,
        ]);
    }
}
