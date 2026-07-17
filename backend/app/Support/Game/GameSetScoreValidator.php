<?php

namespace App\Support\Game;

use Illuminate\Validation\ValidationException;

final class GameSetScoreValidator
{
    public function validate(
        int $player1Score,
        int $player2Score,
        int $pointsPerSet,
        string $errorField = 'player1_score',
    ): void {
        if ($player1Score === $player2Score) {
            throw ValidationException::withMessages([
                $errorField => ['Un set no puede finalizar empatado.'],
            ]);
        }

        $winnerScore = max($player1Score, $player2Score);
        $loserScore = min($player1Score, $player2Score);

        if ($winnerScore < $pointsPerSet) {
            throw ValidationException::withMessages([
                $errorField => [
                    "El ganador del set debe alcanzar al menos {$pointsPerSet} puntos.",
                ],
            ]);
        }

        $isValidFinalScore = $winnerScore === $pointsPerSet
            ? $loserScore <= $pointsPerSet - 2
            : ($winnerScore - $loserScore) === 2;

        if (! $isValidFinalScore) {
            throw ValidationException::withMessages([
                $errorField => ['El marcador no representa un resultado final válido de set.'],
            ]);
        }
    }
}
