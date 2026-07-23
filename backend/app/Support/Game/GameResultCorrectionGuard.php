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

    /**
     * @param  array<int, array{
     *     destination: Game,
     *     slot: 'player1_id'|'player2_id',
     *     oldParticipantId: int,
     *     newParticipantId: int,
     *     context: 'final'|'third_place'|'next_round',
     * }>  $propagations
     */
    public function assertPropagationsSafe(array $propagations): void
    {
        $startedContexts = [];

        foreach ($propagations as $propagation) {
            $oldParticipantId = (int) $propagation['oldParticipantId'];
            $newParticipantId = (int) $propagation['newParticipantId'];

            if ($oldParticipantId === $newParticipantId) {
                continue;
            }

            $destination = $propagation['destination'];
            $slot = $propagation['slot'];
            $context = $propagation['context'];

            if ($this->destinationHasStarted($destination)) {
                $startedContexts[$context] = true;

                continue;
            }

            if ((int) $destination->{$slot} !== $oldParticipantId) {
                throw ValidationException::withMessages([
                    'game' => [$this->slotInconsistencyMessage($context)],
                ]);
            }

            $otherSlot = $slot === 'player1_id' ? 'player2_id' : 'player1_id';

            if ((int) $destination->{$otherSlot} === $newParticipantId) {
                throw ValidationException::withMessages([
                    'dependent_game' => [$this->duplicateParticipantMessage($context)],
                ]);
            }
        }

        if ($startedContexts === []) {
            return;
        }

        $hasFinal = isset($startedContexts['final']);
        $hasThirdPlace = isset($startedContexts['third_place']);

        if ($hasFinal && $hasThirdPlace) {
            throw ValidationException::withMessages([
                'dependent_game' => [
                    'La corrección no puede aplicarse porque la Final y el partido por tercer puesto ya comenzaron o tienen resultados registrados.',
                ],
            ]);
        }

        if ($hasFinal) {
            throw ValidationException::withMessages([
                'dependent_game' => [
                    'La corrección no puede aplicarse porque la Final ya comenzó o tiene resultado registrado.',
                ],
            ]);
        }

        if ($hasThirdPlace) {
            throw ValidationException::withMessages([
                'dependent_game' => [
                    'La corrección no puede aplicarse porque el partido por tercer puesto ya comenzó o tiene resultado registrado.',
                ],
            ]);
        }

        throw ValidationException::withMessages([
            'dependent_game' => [
                'No se puede corregir el resultado porque el partido de la ronda siguiente ya comenzó.',
            ],
        ]);
    }

    private function destinationHasStarted(Game $destination): bool
    {
        if ($destination->status !== GameStatus::Pending) {
            return true;
        }

        if ($destination->relationLoaded('sets') ? $destination->sets->isNotEmpty() : $destination->sets()->exists()) {
            return true;
        }

        return $destination->winner_id !== null;
    }

    private function slotInconsistencyMessage(string $context): string
    {
        return match ($context) {
            'final' => 'No se puede corregir el resultado porque la Final presenta una inconsistencia en el slot esperado.',
            'third_place' => 'No se puede corregir el resultado porque el partido por tercer puesto presenta una inconsistencia en el slot esperado.',
            default => 'No se puede corregir el resultado porque la llave presenta una inconsistencia en la ronda siguiente.',
        };
    }

    private function duplicateParticipantMessage(string $context): string
    {
        return match ($context) {
            'final' => 'No se puede corregir el resultado porque el nuevo jugador ya estaría duplicado en la Final.',
            'third_place' => 'No se puede corregir el resultado porque el nuevo jugador ya estaría duplicado en el partido por tercer puesto.',
            default => 'No se puede corregir el resultado porque el nuevo ganador ya está asignado en la ronda siguiente.',
        };
    }
}
