<?php

namespace App\Http\Requests\Group;

use App\Enums\GroupPlayerStatus;
use App\Enums\GroupPlayerStatusReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetGroupPlayerStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'player_id' => ['required', 'integer', 'exists:players,id'],
            'status' => ['required', 'string', Rule::in([
                GroupPlayerStatus::Withdrawn->value,
                GroupPlayerStatus::Disqualified->value,
            ])],
            'reason' => ['nullable', 'string', Rule::enum(GroupPlayerStatusReason::class)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
