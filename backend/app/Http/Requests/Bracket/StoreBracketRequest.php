<?php

namespace App\Http\Requests\Bracket;

use Illuminate\Foundation\Http\FormRequest;

class StoreBracketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qualifiers_per_group' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
