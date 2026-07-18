<?php

namespace App\Actions\Bracket;

use App\Actions\Game\CreateGameAction;
use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Tournament\TournamentLifecycleGuard;
use App\Support\Bracket\BracketSupport;
use App\Support\Game\GameFormatResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerateBracketNextRoundAction
{
    public function __construct(
        private readonly CreateGameAction $createGame,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(Bracket $bracket): Bracket
    {
        $bracket->loadMissing('competition.tournament');
        TournamentLifecycleGuard::ensureMutableForBracket($bracket);

        $currentRound = (int) $bracket->games()->max('bracket_round');

        if ($currentRound === 0) {
            throw ValidationException::withMessages([
                'bracket' => ['El cuadro eliminatorio no tiene partidos.'],
            ]);
        }

        $currentRoundGames = $bracket->games()
            ->where('bracket_round', $currentRound)
            ->orderBy('bracket_match')
            ->get();

        if ($currentRoundGames->isEmpty()) {
            throw ValidationException::withMessages([
                'bracket' => ['El cuadro eliminatorio no tiene partidos.'],
            ]);
        }

        if ($currentRoundGames->count() === 1) {
            $finalGame = $currentRoundGames->first();

            if (
                $finalGame !== null
                && $finalGame->status === GameStatus::Finished
                && $finalGame->winner_id !== null
            ) {
                throw ValidationException::withMessages([
                    'bracket' => ['El cuadro eliminatorio ya finalizó.'],
                ]);
            }
        }

        $hasUnfinishedGames = $currentRoundGames
            ->contains(fn ($game) => $game->status !== GameStatus::Finished || $game->winner_id === null);

        if ($hasUnfinishedGames) {
            throw ValidationException::withMessages([
                'bracket' => ['La ronda actual todavía tiene partidos sin finalizar.'],
            ]);
        }

        $nextRound = $currentRound + 1;

        if ($bracket->games()->where('bracket_round', $nextRound)->exists()) {
            throw ValidationException::withMessages([
                'bracket' => ['La ronda siguiente ya fue generada.'],
            ]);
        }

        $winners = $currentRoundGames
            ->sortBy('bracket_match')
            ->pluck('winner_id')
            ->map(fn ($winnerId) => (int) $winnerId)
            ->values()
            ->all();

        $roundLabel = BracketSupport::roundLabelFor(count($winners));
        $matchCount = (int) (count($winners) / 2);
        $competitionId = (int) $bracket->competition_id;
        $competition = $bracket->competition()->firstOrFail();
        $matchFormat = GameFormatResolver::resolveForBracketRound($competition, $roundLabel);

        return DB::transaction(function () use (
            $bracket,
            $winners,
            $nextRound,
            $roundLabel,
            $matchCount,
            $competitionId,
            $matchFormat,
            $currentRound,
        ): Bracket {
            for ($matchIndex = 0; $matchIndex < $matchCount; $matchIndex++) {
                ($this->createGame)([
                    'competition_id' => $competitionId,
                    'bracket_id' => $bracket->id,
                    'player1_id' => $winners[$matchIndex * 2],
                    'player2_id' => $winners[($matchIndex * 2) + 1],
                    'round' => $roundLabel,
                    'bracket_round' => $nextRound,
                    'bracket_match' => $matchIndex + 1,
                    'is_bye' => false,
                    'best_of' => $matchFormat['best_of'],
                    'sets_to_win' => $matchFormat['sets_to_win'],
                ]);
            }

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::BRACKET_ROUND_ADVANCED,
                logName: 'bracket',
                subject: $bracket,
                context: AuditContextBuilder::fromBracket($bracket),
                old: [
                    'current_round' => $currentRound,
                ],
                new: [
                    'generated_round' => $nextRound,
                ],
                summary: [
                    'source_round' => $currentRound,
                    'generated_round' => $nextRound,
                    'games_created' => $matchCount,
                    'players_advanced' => count($winners),
                ],
            ));

            return $bracket->load([
                'games.player1:id,first_name,last_name,nickname',
                'games.player2:id,first_name,last_name,nickname',
                'games.winner:id,first_name,last_name,nickname',
                'games.sets',
            ]);
        });
    }
}
