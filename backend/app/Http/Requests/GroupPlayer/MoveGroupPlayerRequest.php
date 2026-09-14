<?php

namespace App\Http\Requests\GroupPlayer;

use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MoveGroupPlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'competition_entry_id' => ['required', 'integer', 'exists:competition_entries,id'],
            'target_group_id' => ['required', 'integer', 'exists:groups,id'],
            'player_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Group|null $source */
            $source = $this->route('group');
            $targetGroupId = (int) $this->input('target_group_id');

            if ($source instanceof Group && (int) $source->id === $targetGroupId) {
                $validator->errors()->add(
                    'target_group_id',
                    'El grupo origen y destino deben ser diferentes.',
                );
            }
        });
    }
}
