<?php

namespace App\Http\Requests\CheckIn;

use App\Enums\CompetitionCheckInBulkAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateCompetitionCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::enum(CompetitionCheckInBulkAction::class)],
        ];
    }
}
