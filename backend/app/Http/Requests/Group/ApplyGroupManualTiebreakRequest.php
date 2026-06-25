<?php

namespace App\Http\Requests\Group;

use App\Enums\ManualTiebreakReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyGroupManualTiebreakRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'player_ids' => ['required', 'array', 'min:2'],
            'player_ids.*' => ['required', 'integer', 'distinct', 'exists:players,id'],
            'reason' => ['required', 'string', Rule::enum(ManualTiebreakReason::class)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
