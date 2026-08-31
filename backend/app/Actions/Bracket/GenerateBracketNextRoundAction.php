<?php

namespace App\Actions\Bracket;

use App\Actions\Game\CreateGameAction;
use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Enums\TeamTieStatus;
use App\Models\Bracket;
use App\Models\Game;
use App\Models\TeamTie;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Bracket\BracketPodiumSupport;
use App\Support\Bracket\BracketSupport;
use App\Support\Game\GameFormatResolver;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerateBracketNextRoundAction
{
    public function __construct(
        private readonly CreateGameAction $createGame,
        private readonly CreateBracketTeamTieAction $createBracketTeamTie,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(Bracket $bracket): Bracket
    {
        $bracket->loadMissing('competition.tournament');
        TournamentLifecycleGuard::ensureMutableForBracket($bracket);

        if ($bracket->competition?->isTeam()) {
            return $this->generateTeamNextRound($bracket);
        }

        return $this->generateGameNextRound($bracket);
    }

    private function generateGameNextRound(Bracket $bracket): Bracket
    {
        $currentRound = (int) $bracket->games()->mainBracket()->max('bracket_round');

        if ($currentRound === 0) {
            throw ValidationException::withMessages([
                'bracket' => ['El cuadro eliminatorio no tiene partidos.'],
            ]);
        }

        $currentRoundGames = $bracket->games()
            ->mainBracket()
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
                && $finalGame->winner_entry_id !== null
            ) {
                throw ValidationException::withMessages([
                    'bracket' => ['El cuadro eliminatorio ya finalizó.'],
                ]);
            }
        }

        $hasUnfinishedGames = $currentRoundGames
            ->contains(fn ($game) => $game->status !== GameStatus::Finished || $game->winner_entry_id === null);

        if ($hasUnfinishedGames) {
            throw ValidationException::withMessages([
                'bracket' => ['La ronda actual todavía tiene partidos sin finalizar.'],
            ]);
        }

        $nextRound = $currentRound + 1;

        if ($bracket->games()->mainBracket()->where('bracket_round', $nextRound)->exists()) {
            throw ValidationException::withMessages([
                'bracket' => ['La ronda siguiente ya fue generada.'],
            ]);
        }

        $winners = $currentRoundGames
            ->sortBy('bracket_match')
            ->pluck('winner_entry_id')
            ->map(fn ($winnerEntryId) => (int) $winnerEntryId)
            ->values()
            ->all();

        $roundLabel = BracketSupport::roundLabelFor(count($winners));
        $matchCount = (int) (count($winners) / 2);
        $competitionId = (int) $bracket->competition_id;
        $competition = $bracket->competition()->firstOrFail();
        $matchFormat = GameFormatResolver::resolveForBracketRound($competition, $roundLabel);
        $shouldCreateThirdPlace = $roundLabel === 'Final'
            && BracketPodiumSupport::requiresPlayoffThirdPlace($competition, $bracket);

        return DB::transaction(function () use (
            $bracket,
            $winners,
            $nextRound,
            $roundLabel,
            $matchCount,
            $competitionId,
            $competition,
            $matchFormat,
            $currentRound,
            $shouldCreateThirdPlace,
        ): Bracket {
            for ($matchIndex = 0; $matchIndex < $matchCount; $matchIndex++) {
                ($this->createGame)([
                    'competition_id' => $competitionId,
                    'bracket_id' => $bracket->id,
                    'bracket_purpose' => BracketGamePurpose::Main,
                    'entry1_id' => $winners[$matchIndex * 2],
                    'entry2_id' => $winners[($matchIndex * 2) + 1],
                    'round' => $roundLabel,
                    'bracket_round' => $nextRound,
                    'bracket_match' => $matchIndex + 1,
                    'is_bye' => false,
                    'best_of' => $matchFormat['best_of'],
                    'sets_to_win' => $matchFormat['sets_to_win'],
                ]);
            }

            $thirdPlaceGame = null;
            $thirdPlaceGameCreated = false;

            if ($shouldCreateThirdPlace) {
                $existingThirdPlace = BracketPodiumSupport::findThirdPlaceGame($bracket);

                if ($existingThirdPlace === null) {
                    $participants = BracketPodiumSupport::thirdPlaceParticipants($bracket);

                    if (count($participants) === 2) {
                        $thirdPlaceFormat = GameFormatResolver::resolveForBracketPurpose(
                            $competition,
                            BracketGamePurpose::ThirdPlace,
                        );

                        $thirdPlaceGame = ($this->createGame)([
                            'competition_id' => $competitionId,
                            'bracket_id' => $bracket->id,
                            'bracket_purpose' => BracketGamePurpose::ThirdPlace,
                            'entry1_id' => $participants[0]->id,
                            'entry2_id' => $participants[1]->id,
                            'round' => 'Tercer puesto',
                            'bracket_round' => null,
                            'bracket_match' => 1,
                            'is_bye' => false,
                            'best_of' => $thirdPlaceFormat['best_of'],
                            'sets_to_win' => $thirdPlaceFormat['sets_to_win'],
                        ]);
                        $thirdPlaceGameCreated = true;
                    }
                } else {
                    $thirdPlaceGame = $existingThirdPlace;
                }
            }

            $this->auditRoundAdvanced(
                bracket: $bracket,
                currentRound: $currentRound,
                nextRound: $nextRound,
                matchesCreated: $matchCount,
                winnersAdvanced: count($winners),
                thirdPlaceCreated: $shouldCreateThirdPlace ? $thirdPlaceGameCreated : null,
                thirdPlaceId: $thirdPlaceGame?->id,
            );

            return $bracket->load(array_map(
                fn (string $relation): string => 'games.'.$relation,
                Game::DISPLAY_RELATIONS,
            ));
        });
    }

    private function generateTeamNextRound(Bracket $bracket): Bracket
    {
        $currentRound = (int) $bracket->teamTies()->mainBracket()->max('bracket_round');

        if ($currentRound === 0) {
            throw ValidationException::withMessages([
                'bracket' => ['El cuadro eliminatorio no tiene enfrentamientos.'],
            ]);
        }

        $currentRoundTeamTies = $bracket->teamTies()
            ->mainBracket()
            ->where('bracket_round', $currentRound)
            ->orderBy('bracket_match')
            ->get();

        if ($currentRoundTeamTies->isEmpty()) {
            throw ValidationException::withMessages([
                'bracket' => ['El cuadro eliminatorio no tiene enfrentamientos.'],
            ]);
        }

        if ($currentRoundTeamTies->count() === 1) {
            $finalTeamTie = $currentRoundTeamTies->first();

            if (
                $finalTeamTie !== null
                && $finalTeamTie->status === TeamTieStatus::Finished
                && $finalTeamTie->winner_entry_id !== null
            ) {
                throw ValidationException::withMessages([
                    'bracket' => ['El cuadro eliminatorio ya finalizó.'],
                ]);
            }
        }

        $hasUnfinishedTeamTies = $currentRoundTeamTies
            ->contains(fn ($teamTie) => $teamTie->status !== TeamTieStatus::Finished || $teamTie->winner_entry_id === null);

        if ($hasUnfinishedTeamTies) {
            throw ValidationException::withMessages([
                'bracket' => ['La ronda actual todavía tiene enfrentamientos sin finalizar.'],
            ]);
        }

        $nextRound = $currentRound + 1;

        if ($bracket->teamTies()->mainBracket()->where('bracket_round', $nextRound)->exists()) {
            throw ValidationException::withMessages([
                'bracket' => ['La ronda siguiente ya fue generada.'],
            ]);
        }

        $winners = $currentRoundTeamTies
            ->sortBy('bracket_match')
            ->pluck('winner_entry_id')
            ->map(fn ($winnerEntryId) => (int) $winnerEntryId)
            ->values()
            ->all();

        $roundLabel = BracketSupport::roundLabelFor(count($winners));
        $matchCount = (int) (count($winners) / 2);
        $competition = $bracket->competition()->firstOrFail();
        $shouldCreateThirdPlace = $roundLabel === 'Final'
            && BracketPodiumSupport::requiresPlayoffThirdPlace($competition, $bracket);

        return DB::transaction(function () use (
            $bracket,
            $winners,
            $nextRound,
            $roundLabel,
            $matchCount,
            $competition,
            $currentRound,
            $shouldCreateThirdPlace,
        ): Bracket {
            for ($matchIndex = 0; $matchIndex < $matchCount; $matchIndex++) {
                ($this->createBracketTeamTie)(
                    competition: $competition,
                    bracket: $bracket,
                    entry1Id: $winners[$matchIndex * 2],
                    entry2Id: $winners[($matchIndex * 2) + 1],
                    bracketRound: $nextRound,
                    bracketMatch: $matchIndex + 1,
                    bracketPurpose: BracketGamePurpose::Main,
                    roundLabel: $roundLabel,
                );
            }

            $thirdPlaceTeamTie = null;
            $thirdPlaceCreated = false;

            if ($shouldCreateThirdPlace) {
                $existingThirdPlace = BracketPodiumSupport::findThirdPlaceTeamTie($bracket);

                if ($existingThirdPlace === null) {
                    $participants = BracketPodiumSupport::thirdPlaceParticipants($bracket);

                    if (count($participants) === 2) {
                        $thirdPlaceTeamTie = $this->createBracketTeamTie->createThirdPlace(
                            competition: $competition,
                            bracket: $bracket,
                            entry1Id: $participants[0]->id,
                            entry2Id: $participants[1]->id,
                        );
                        $thirdPlaceCreated = true;
                    }
                } else {
                    $thirdPlaceTeamTie = $existingThirdPlace;
                }
            }

            $this->auditRoundAdvanced(
                bracket: $bracket,
                currentRound: $currentRound,
                nextRound: $nextRound,
                matchesCreated: $matchCount,
                winnersAdvanced: count($winners),
                thirdPlaceCreated: $shouldCreateThirdPlace ? $thirdPlaceCreated : null,
                thirdPlaceId: $thirdPlaceTeamTie?->id,
                matchEntity: 'team_tie',
            );

            return $bracket->load(array_map(
                fn (string $relation): string => 'teamTies.'.$relation,
                TeamTie::BRACKET_OVERVIEW_RELATIONS,
            ));
        });
    }

    private function auditRoundAdvanced(
        Bracket $bracket,
        int $currentRound,
        int $nextRound,
        int $matchesCreated,
        int $winnersAdvanced,
        ?bool $thirdPlaceCreated,
        ?int $thirdPlaceId,
        ?string $matchEntity = null,
    ): void {
        $auditSummary = [
            'source_round' => $currentRound,
            'generated_round' => $nextRound,
            'games_created' => $matchesCreated,
            'players_advanced' => $winnersAdvanced,
        ];

        if ($matchEntity !== null) {
            $auditSummary['match_entity'] = $matchEntity;
            $auditSummary['team_ties_created'] = $matchesCreated;
        }

        if ($thirdPlaceCreated !== null) {
            $auditSummary['third_place_game_created'] = $thirdPlaceCreated;
            $auditSummary['third_place_game_id'] = $thirdPlaceId;
            $auditSummary['third_place_team_tie_id'] = $thirdPlaceId;
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
            summary: $auditSummary,
        ));
    }
}
