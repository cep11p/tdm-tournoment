<?php

namespace App\Http\Requests\Group;

use App\Enums\CompetitionType;
use App\Enums\ManualTiebreakReason;
use App\Models\Group;
use App\Support\Competition\BuildGroupEntryIndexForGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class ApplyGroupManualTiebreakRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entry_ids' => ['sometimes', 'array', 'min:2'],
            'entry_ids.*' => ['required', 'integer', 'distinct', 'exists:competition_entries,id'],
            'player_ids' => ['sometimes', 'array', 'min:2'],
            'player_ids.*' => ['required', 'integer', 'distinct', 'exists:players,id'],
            'reason' => ['required', 'string', Rule::enum(ManualTiebreakReason::class)],
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

            if (! $group instanceof Group) {
                return;
            }

            $group->loadMissing('competition');
            $type = $group->competition?->type instanceof CompetitionType
                ? $group->competition->type
                : null;
            $isMultiMember = $type?->isMultiMember() === true;
            $hasEntryIds = is_array($this->input('entry_ids'));
            $hasPlayerIds = is_array($this->input('player_ids'));

            if ($isMultiMember && ! $hasEntryIds) {
                $label = $type->isTeam() ? 'equipos' : 'parejas';
                $validator->errors()->add('entry_ids', "Se requiere entry_ids para desempatar {$label}.");

                return;
            }

            if (! $isMultiMember && ! $hasEntryIds && ! $hasPlayerIds) {
                $validator->errors()->add('entry_ids', 'Se requiere entry_ids o player_ids.');

                return;
            }

            if ($isMultiMember && $hasPlayerIds) {
                $label = $type->isTeam() ? 'equipos' : 'parejas';
                $validator->errors()->add('player_ids', "No se puede desempatar {$label} usando player_ids.");

                return;
            }

            if ($hasEntryIds && $hasPlayerIds) {
                $validator->errors()->add('entry_ids', 'Enviá solo entry_ids o player_ids, no ambos.');
            }
        });
    }

    /**
     * @return array<int, int>
     */
    public function resolvedEntryIds(): array
    {
        $entryIds = $this->validated('entry_ids');

        if (is_array($entryIds)) {
            return array_values(array_map('intval', $entryIds));
        }

        /** @var Group $group */
        $group = $this->route('group');
        $index = app(BuildGroupEntryIndexForGroup::class)($group);
        $resolved = [];

        foreach ($this->validated('player_ids') as $playerId) {
            $entryId = $index->entryIdForPlayer((int) $playerId);

            if ($entryId === null) {
                throw ValidationException::withMessages([
                    'player_ids' => ['Uno o más jugadores no pertenecen al grupo.'],
                ]);
            }

            $resolved[] = $entryId;
        }

        return $resolved;
    }

    public function usesPlayerIds(): bool
    {
        return is_array($this->validated('player_ids'));
    }
}
