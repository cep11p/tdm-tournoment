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
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
