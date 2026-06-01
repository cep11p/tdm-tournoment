<?php

namespace App\Services;

use App\Models\Match;
use App\Models\MatchSet;
use Illuminate\Support\Facades\DB;

class MatchService
{
    /**
     * Calcula el ganador del partido basándose en los sets cargados.
     *
     * Recorre todos los sets del partido, cuenta cuántos ganó cada jugador
     * y, si alguno alcanzó sets_to_win, cierra el partido con su ganador.
     */
    public function calculateWinner(Match $match): void
    {
        if ($match->isFinished()) {
            return;
        }

        $setsToWin = $match->competition->sets_to_win;
        $sets      = $match->sets;

        $player1Wins = 0;
        $player2Wins = 0;

        foreach ($sets as $set) {
            if ($set->player1_score > $set->player2_score) {
                $player1Wins++;
            } elseif ($set->player2_score > $set->player1_score) {
                $player2Wins++;
            }
        }

        if ($player1Wins >= $setsToWin) {
            $this->closeMatch($match, $match->player1_id);
        } elseif ($player2Wins >= $setsToWin) {
            $this->closeMatch($match, $match->player2_id);
        } elseif ($sets->isNotEmpty()) {
            $match->update(['status' => 'in_progress']);
        }
    }

    /**
     * Agrega un set al partido y recalcula el ganador.
     *
     * Si el partido ya está finalizado, lanza una excepción.
     */
    public function addSet(Match $match, int $setNumber, int $player1Score, int $player2Score): MatchSet
    {
        if ($match->isFinished()) {
            throw new \DomainException("No se puede cargar un set en un partido ya finalizado.");
        }

        $set = DB::transaction(function () use ($match, $setNumber, $player1Score, $player2Score) {
            $set = MatchSet::updateOrCreate(
                ['match_id' => $match->id, 'set_number' => $setNumber],
                ['player1_score' => $player1Score, 'player2_score' => $player2Score],
            );

            $match->load('sets', 'competition');
            $this->calculateWinner($match);

            return $set;
        });

        return $set;
    }

    private function closeMatch(Match $match, int $winnerId): void
    {
        $match->update([
            'winner_id' => $winnerId,
            'status'    => 'finished',
        ]);
    }
}
