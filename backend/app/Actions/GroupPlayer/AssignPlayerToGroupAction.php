<?php

namespace App\Actions\GroupPlayer;

use App\Models\Group;
use App\Models\GroupPlayer;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class AssignPlayerToGroupAction
{
    public function __invoke(array $payload): GroupPlayer
    {
        $group = Group::query()->findOrFail($payload['group_id']);
        $playerId = (int) $payload['player_id'];

        $alreadyAssigned = GroupPlayer::query()
            ->where('player_id', $playerId)
            ->whereHas('group', fn ($query) => $query->where('competition_id', $group->competition_id))
            ->exists();

        if ($alreadyAssigned) {
            throw ValidationException::withMessages([
                'player_id' => ['El jugador ya está asignado a un grupo de esta competencia.'],
            ]);
        }

        try {
            return GroupPlayer::query()->create([
                'group_id' => $group->id,
                'player_id' => $playerId,
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'player_id' => ['El jugador ya está asignado a este grupo.'],
                ]);
            }

            throw $exception;
        }
    }
}
