<?php

namespace App\Http\Requests\TeamTie;

use App\Enums\TeamTieModality;
use App\Models\TeamTieGame;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTeamTieGameLineupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entry1_player_ids' => ['required', 'array'],
            'entry1_player_ids.*' => ['integer', 'distinct', 'min:1'],
            'entry2_player_ids' => ['required', 'array'],
            'entry2_player_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var TeamTieGame|null $teamTieGame */
            $teamTieGame = $this->route('team_tie_game');

            if (! $teamTieGame instanceof TeamTieGame) {
                return;
            }

            $requiredPerSide = $teamTieGame->modality === TeamTieModality::Doubles ? 2 : 1;
            $entry1Count = count($this->input('entry1_player_ids', []));
            $entry2Count = count($this->input('entry2_player_ids', []));

            if ($entry1Count !== $requiredPerSide) {
                $validator->errors()->add(
                    'entry1_player_ids',
                    sprintf('Se requieren exactamente %d jugador(es) para el lado 1.', $requiredPerSide),
                );
            }

            if ($entry2Count !== $requiredPerSide) {
                $validator->errors()->add(
                    'entry2_player_ids',
                    sprintf('Se requieren exactamente %d jugador(es) para el lado 2.', $requiredPerSide),
                );
            }
        });
    }
}
