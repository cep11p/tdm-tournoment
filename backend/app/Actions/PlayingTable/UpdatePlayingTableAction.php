<?php

namespace App\Actions\PlayingTable;

use App\Models\PlayingTable;
use App\Models\Tournament;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UpdatePlayingTableAction
{
    /**
     * @param  array{number?: int, name?: string|null, active?: bool, sort_order?: int}  $payload
     */
    public function __invoke(Tournament $tournament, PlayingTable $playingTable, array $payload): PlayingTable
    {
        if ((int) $playingTable->tournament_id !== (int) $tournament->id) {
            throw new NotFoundHttpException;
        }

        TournamentLifecycleGuard::ensureMutable($tournament);

        try {
            $playingTable->fill($payload);
            $playingTable->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'number' => [CreatePlayingTableAction::DUPLICATE_NUMBER_MESSAGE],
            ]);
        }

        return $playingTable->refresh();
    }
}
