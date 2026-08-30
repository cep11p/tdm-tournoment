<?php

namespace App\Http\Requests\Group;

use App\Enums\CompetitionType;
use App\Enums\GroupPlayerStatus;
use App\Enums\GroupPlayerStatusReason;
use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SetGroupPlayerStatusRequest extends FormRequest
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

        $identifierRules = $type?->isMultiMember() === true
            ? [
                'competition_entry_id' => ['required', 'integer', 'exists:competition_entries,id'],
            ]
            : [
                'player_id' => ['required_without:competition_entry_id', 'integer', 'exists:players,id'],
                'competition_entry_id' => ['required_without:player_id', 'integer', 'exists:competition_entries,id'],
            ];

        return [
            ...$identifierRules,
            'status' => ['required', 'string', Rule::in([
                GroupPlayerStatus::Withdrawn->value,
                GroupPlayerStatus::Disqualified->value,
            ])],
            'reason' => ['nullable', 'string', Rule::enum(GroupPlayerStatusReason::class)],
            'notes' => ['nullable', 'string', 'max:500'],
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
                $validator->errors()->add('player_id', "No se puede cambiar el estado de un {$label} usando player_id.");
            }
        });
    }
}
