<?php

namespace App\Support\Game;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Support\Competition\CompetitionResultResolver;
use Illuminate\Validation\ValidationException;

final class GameResultCorrectionGuard
{
    public function __construct(
        private readonly GameDependencyResolver $dependencyResolver,
    ) {}

    public function assertSourceCorrectable(Game $game): void
    {
        if ($game->is_bye) {
            throw ValidationException::withMessages([
                'game' => ['No se puede corregir el resultado de un partido con BYE.'],
            ]);
        }

        if ($game->status !== GameStatus::Finished) {
            throw ValidationException::withMessages([
                'game' => ['Solo se pueden corregir partidos finalizados.'],
            ]);
        }

        if ($game->sets->isEmpty()) {
            throw ValidationException::withMessages([
                'game' => ['El partido no tiene sets cargados para corregir.'],
            ]);
        }

        if ($game->player1_id === null || $game->player2_id === null) {
            throw ValidationException::withMessages([
                'game' => ['El partido no tiene ambos jugadores asignados.'],
            ]);
        }

        $competition = $game->competition;
        $setsToWin = (int) ($game->sets_to_win ?? $competition?->sets_to_win);

        if ($setsToWin < 1) {
            throw ValidationException::withMessages([
                'game' => ['El partido no tiene una configuración válida de sets para ganar.'],
            ]);
        }

        if ($competition !== null && CompetitionResultResolver::resolve($competition) !== null) {
            throw ValidationException::withMessages([
                'competition' => ['No se puede corregir el resultado porque la competencia ya tiene una final terminada.'],
            ]);
        }

        if ($game->group_id !== null && $competition !== null && $competition->brackets()->exists()) {
            throw ValidationException::withMessages([
                'game' => ['No se puede corregir el resultado porque la llave ya fue generada.'],
            ]);
        }
    }

    public function assertNoRoundBeyondImmediate(Game $game): void
    {
        if (! $this->dependencyResolver->hasRoundBeyondImmediate($game)) {
            return;
        }

        throw ValidationException::withMessages([
            'game' => ['No se puede corregir el resultado porque la llave ya avanzó más de una ronda.'],
        ]);
    }

    public function assertPropagationSafe(
        Game $source,
        Game $destination,
        string $slot,
        int $oldWinnerId,
        int $newWinnerId,
    ): void {
        if ($destination->status !== GameStatus::Pending) {
            throw ValidationException::withMessages([
                'dependent_game' => ['No se puede corregir el resultado porque el partido de la ronda siguiente ya comenzó.'],
            ]);
        }

        if ($destination->sets()->exists()) {
            throw ValidationException::withMessages([
                'dependent_game' => ['No se puede corregir el resultado porque el partido de la ronda siguiente ya comenzó.'],
            ]);
        }

        if ($destination->winner_id !== null) {
            throw ValidationException::withMessages([
                'dependent_game' => ['No se puede corregir el resultado porque el partido de la ronda siguiente ya comenzó.'],
            ]);
        }

        if ((int) $destination->{$slot} !== $oldWinnerId) {
            throw ValidationException::withMessages([
                'game' => ['No se puede corregir el resultado porque la llave presenta una inconsistencia en la ronda siguiente.'],
            ]);
        }

        if ((int) $oldWinnerId === (int) $newWinnerId) {
            return;
        }

        $otherSlot = $slot === 'player1_id' ? 'player2_id' : 'player1_id';

        if ((int) $destination->{$otherSlot} === (int) $newWinnerId) {
            throw ValidationException::withMessages([
                'dependent_game' => ['No se puede corregir el resultado porque el nuevo ganador ya está asignado en la ronda siguiente.'],
            ]);
        }
    }
}
