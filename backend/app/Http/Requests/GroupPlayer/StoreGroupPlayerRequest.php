<?php

namespace App\Http\Requests\GroupPlayer;

use App\Rules\Registration\PlayerIsRegisteredInCompetitionRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreGroupPlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var \App\Models\Group|null $group */
        $group = $this->route('group');

        return [
            'player_id' => [
                'required',
                'integer',
                'exists:players,id',
                new PlayerIsRegisteredInCompetitionRule($group?->competition),
            ],
        ];
    }
}
