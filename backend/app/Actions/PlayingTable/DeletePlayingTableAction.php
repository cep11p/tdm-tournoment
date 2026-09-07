<?php

namespace App\Actions\PlayingTable;

use App\Models\PlayingTable;
use App\Models\Tournament;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeletePlayingTableAction
{
    public const REFERENCED_MESSAGE = 'No se puede eliminar la mesa porque tiene un partido asociado.';

    public function __invoke(Tournament $tournament, PlayingTable $playingTable): void
    {
        if ((int) $playingTable->tournament_id !== (int) $tournament->id) {
            throw new NotFoundHttpException;
        }

        TournamentLifecycleGuard::ensureMutable($tournament);

        if ($playingTable->games()->exists()) {
            throw ValidationException::withMessages([
                'playing_table' => [self::REFERENCED_MESSAGE],
            ]);
        }

        $playingTable->delete();
    }
}
