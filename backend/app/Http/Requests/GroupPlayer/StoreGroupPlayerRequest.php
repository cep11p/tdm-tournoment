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
        $type = $group?->competition?->type instanceof CompetitionType
            ? $group->competition->type
            : null;

        if ($type?->isMultiMember() === true) {
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
            $type = $group?->competition?->type instanceof CompetitionType
                ? $group->competition->type
                : null;

            if ($type?->isMultiMember() !== true) {
                return;
            }

            if ($this->filled('player_id')) {
                $label = $type->isTeam() ? 'equipo' : 'pareja';

                $validator->errors()->add(
                    'player_id',
                    "No se puede asignar un {$label} al grupo usando player_id.",
                );
            }
        });
    }
}
