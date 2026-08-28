<?php

namespace App\Http\Requests\GroupPlayer;

use App\Enums\CompetitionType;
use App\Models\Group;
use App\Rules\CompetitionEntry\PlayerHasCompetitionEntryRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreGroupPlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Group|null $group */
        $group = $this->route('group');
        $group?->loadMissing('competition');
        $isDoubles = $group?->competition?->type === CompetitionType::Doubles;

        if ($isDoubles) {
            return [
                'competition_entry_id' => [
                    'required',
                    'integer',
                    'exists:competition_entries,id',
                ],
                'player_id' => ['prohibited'],
            ];
        }

        return [
            'player_id' => [
                'required_without:competition_entry_id',
                'integer',
                'exists:players,id',
                new PlayerHasCompetitionEntryRule($group?->competition),
            ],
            'competition_entry_id' => [
                'required_without:player_id',
                'integer',
                'exists:competition_entries,id',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Group|null $group */
            $group = $this->route('group');

            if (! $group instanceof Group || $group->competition?->type !== CompetitionType::Doubles) {
                return;
            }

            if ($this->filled('player_id')) {
                $validator->errors()->add('player_id', 'No se puede asignar una pareja al grupo usando player_id.');
            }
        });
    }
}
