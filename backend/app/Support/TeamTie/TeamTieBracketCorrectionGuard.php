<?php

namespace App\Support\TeamTie;

use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Enums\TeamTieStatus;
use App\Models\Game;
use App\Models\TeamTie;
use App\Support\Competition\CompetitionResultResolver;
use Illuminate\Validation\ValidationException;

final class TeamTieBracketCorrectionGuard
{
    public function __construct(
        private readonly TeamTieDependencyResolver $dependencyResolver,
    ) {}

    public function assertSourceCorrectable(TeamTie $teamTie): void
    {
        if ($teamTie->is_bye) {
            throw ValidationException::withMessages([
                'team_tie' => ['No se puede corregir el resultado de un enfrentamiento con BYE.'],
            ]);
        }

        if ($teamTie->bracket_id === null) {
            throw ValidationException::withMessages([
                'team_tie' => ['El enfrentamiento no pertenece a una llave eliminatoria.'],
            ]);
        }

        if ($teamTie->status !== TeamTieStatus::Finished) {
            throw ValidationException::withMessages([
                'team_tie' => ['Solo se pueden corregir enfrentamientos finalizados.'],
            ]);
        }

        if ($teamTie->winner_entry_id === null) {
            throw ValidationException::withMessages([
                'team_tie' => ['El enfrentamiento no tiene un ganador definido.'],
            ]);
        }

        $competition = $teamTie->competition;

        if ($competition !== null && CompetitionResultResolver::resolve($competition) !== null) {
            if (! self::isPodiumCorrectableTeamTie($teamTie)) {
                throw ValidationException::withMessages([
                    'competition' => ['No se puede corregir el resultado porque la competencia ya tiene una final terminada.'],
                ]);
            }
        }
    }

    public function assertNoRoundBeyondImmediate(TeamTie $teamTie): void
    {
        if (! $this->dependencyResolver->hasRoundBeyondImmediate($teamTie)) {
            return;
        }

        throw ValidationException::withMessages([
            'team_tie' => ['No se puede corregir el resultado porque la llave ya avanzó más de una ronda.'],
        ]);
    }

    /**
     * @param  array<int, array{
     *     destination: TeamTie,
     *     slot: 'entry1_id'|'entry2_id',
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
                    'team_tie' => [$this->slotInconsistencyMessage($context)],
                ]);
            }

            $otherSlot = $slot === 'entry1_id' ? 'entry2_id' : 'entry1_id';

            if ((int) $destination->{$otherSlot} === $newParticipantId) {
                throw ValidationException::withMessages([
                    'dependent_team_tie' => [$this->duplicateParticipantMessage($context)],
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
                'dependent_team_tie' => [
                    'La corrección no puede aplicarse porque la Final y el partido por tercer puesto ya comenzaron o tienen resultados registrados.',
                ],
            ]);
        }

        if ($hasFinal) {
            throw ValidationException::withMessages([
                'dependent_team_tie' => [
                    'La corrección no puede aplicarse porque la Final ya comenzó o tiene resultado registrado.',
                ],
            ]);
        }

        if ($hasThirdPlace) {
            throw ValidationException::withMessages([
                'dependent_team_tie' => [
                    'La corrección no puede aplicarse porque el partido por tercer puesto ya comenzó o tiene resultado registrado.',
                ],
            ]);
        }

        throw ValidationException::withMessages([
            'dependent_team_tie' => [
                'No se puede corregir el resultado porque el enfrentamiento de la ronda siguiente ya comenzó.',
            ],
        ]);
    }

    public function destinationHasStarted(TeamTie $destination): bool
    {
        if ($destination->status !== TeamTieStatus::Pending) {
            return true;
        }

        if ($destination->winner_entry_id !== null) {
            return true;
        }

        $destination->loadMissing(['teamTieGames.members', 'teamTieGames.game.sets']);

        foreach ($destination->teamTieGames as $teamTieGame) {
            if ($teamTieGame->isLineupComplete()) {
                return true;
            }

            $game = $teamTieGame->game;

            if ($game === null) {
                continue;
            }

            if ($game->status !== GameStatus::Pending) {
                return true;
            }

            if ($game->relationLoaded('sets') ? $game->sets->isNotEmpty() : $game->sets()->exists()) {
                return true;
            }
        }

        return false;
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
            'final' => 'No se puede corregir el resultado porque el nuevo equipo ya estaría duplicado en la Final.',
            'third_place' => 'No se puede corregir el resultado porque el nuevo equipo ya estaría duplicado en el partido por tercer puesto.',
            default => 'No se puede corregir el resultado porque el nuevo ganador ya está asignado en la ronda siguiente.',
        };
    }

    private static function isPodiumCorrectableTeamTie(TeamTie $teamTie): bool
    {
        if ($teamTie->bracket_id === null) {
            return false;
        }

        if ($teamTie->round === 'Final') {
            return true;
        }

        $purpose = $teamTie->bracket_purpose instanceof BracketGamePurpose
            ? $teamTie->bracket_purpose
            : BracketGamePurpose::tryFrom((string) ($teamTie->bracket_purpose ?? BracketGamePurpose::Main->value));

        return $purpose === BracketGamePurpose::ThirdPlace;
    }
}
