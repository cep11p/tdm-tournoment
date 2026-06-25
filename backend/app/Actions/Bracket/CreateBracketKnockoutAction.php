<?php

namespace App\Actions\Bracket;

use App\Actions\Game\CreateGameAction;
use App\Data\Competition\CompetitionStandingData;
use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\Group;
use App\Support\Bracket\BracketSupport;
use App\Support\Game\GameFormatResolver;
use App\Support\Group\GroupStandingsCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateBracketKnockoutAction
{
    public function __construct(
        private readonly CreateGameAction $createGame,
        private readonly GroupStandingsCalculator $groupStandingsCalculator,
    ) {}

    public function __invoke(Competition $competition, array $payload): Bracket
    {
        if ($competition->brackets()->exists()) {
            throw ValidationException::withMessages([
                'competition' => ['La competencia ya tiene un cuadro eliminatorio.'],
            ]);
        }

        $groups = $competition->groups()->get();

        if ($groups->isEmpty()) {
            throw ValidationException::withMessages([
                'competition' => ['La competencia no tiene grupos.'],
            ]);
        }

        $qualifiersPerGroup = (int) $competition->qualified_per_group;
        $qualifiers = collect();

        foreach ($groups as $group) {
            $groupQualifiers = $this->qualifiersFromGroup($group, $qualifiersPerGroup);
            $qualifiers = $qualifiers->concat($groupQualifiers);
        }

        $seededQualifiers = $qualifiers
            ->sort(function (CompetitionStandingData $left, CompetitionStandingData $right): int {
                return [$right->won, $left->lost, strtolower($left->playerName)]
                    <=>
                    [$left->won, $right->lost, strtolower($right->playerName)];
            })
            ->values();

        $qualifierCount = $seededQualifiers->count();

        if ($qualifierCount < 2) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    'Se requieren al menos 2 clasificados para generar el cuadro eliminatorio.',
                ],
            ]);
        }

        $bracketSize = BracketSupport::nextPowerOfTwo($qualifierCount);

        if ($bracketSize > BracketSupport::MAX_BRACKET_SIZE) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    sprintf(
                        'El cuadro eliminatorio admite hasta %d clasificados. La configuración actual produce %d.',
                        BracketSupport::MAX_BRACKET_SIZE,
                        $qualifierCount
                    ),
                ],
            ]);
        }

        $byesCount = $bracketSize - $qualifierCount;
        $roundLabel = BracketSupport::roundLabelFor($bracketSize);
        $matchFormat = GameFormatResolver::resolveForBracketRound($competition, $roundLabel);
        $name = trim($payload['name'] ?? '');

        if ($name === '') {
            $name = 'Llave - ' . $competition->name;
        }

        return DB::transaction(function () use (
            $competition,
            $seededQualifiers,
            $qualifierCount,
            $bracketSize,
            $byesCount,
            $roundLabel,
            $matchFormat,
            $name,
            $qualifiersPerGroup
        ): Bracket {
            $bracket = Bracket::query()->create([
                'competition_id' => $competition->id,
                'name' => $name,
                'qualifiers_per_group' => $qualifiersPerGroup,
                'bracket_size' => $bracketSize,
                'byes_count' => $byesCount,
            ]);

            $playerIds = $seededQualifiers
                ->pluck('playerId')
                ->map(fn (int $playerId) => $playerId)
                ->all();

            $matchCount = (int) ($bracketSize / 2);

            for ($matchIndex = 0; $matchIndex < $matchCount; $matchIndex++) {
                $topSeed = $matchIndex + 1;
                $bottomSeed = $bracketSize - $matchIndex;
                $topPlayerId = $playerIds[$topSeed - 1];
                $bottomPlayerId = $bottomSeed <= $qualifierCount
                    ? $playerIds[$bottomSeed - 1]
                    : null;

                if ($bottomPlayerId === null) {
                    ($this->createGame)([
                        'competition_id' => $competition->id,
                        'bracket_id' => $bracket->id,
                        'player1_id' => $topPlayerId,
                        'player2_id' => null,
                        'winner_id' => $topPlayerId,
                        'status' => GameStatus::Finished,
                        'finished_at' => now(),
                        'is_bye' => true,
                        'round' => $roundLabel,
                        'bracket_round' => 1,
                        'bracket_match' => $matchIndex + 1,
                    ]);

                    continue;
                }

                ($this->createGame)([
                    'competition_id' => $competition->id,
                    'bracket_id' => $bracket->id,
                    'player1_id' => $topPlayerId,
                    'player2_id' => $bottomPlayerId,
                    'round' => $roundLabel,
                    'bracket_round' => 1,
                    'bracket_match' => $matchIndex + 1,
                    'is_bye' => false,
                    'best_of' => $matchFormat['best_of'],
                    'sets_to_win' => $matchFormat['sets_to_win'],
                ]);
            }

            return $bracket->load([
                'games.player1:id,first_name,last_name,nickname',
                'games.player2:id,first_name,last_name,nickname',
                'games.winner:id,first_name,last_name,nickname',
                'games.sets',
            ]);
        });
    }

    /**
     * @return Collection<int, CompetitionStandingData>
     */
    private function qualifiersFromGroup(Group $group, int $qualifiersPerGroup): Collection
    {
        $groupPlayers = $group->groupPlayers()
            ->with('player:id,first_name,last_name')
            ->get();

        if ($groupPlayers->count() < 2) {
            throw ValidationException::withMessages([
                'group' => [sprintf('El grupo "%s" necesita al menos 2 jugadores.', $group->name)],
            ]);
        }

        if (! $group->games()->exists()) {
            throw ValidationException::withMessages([
                'group' => [sprintf('El grupo "%s" no tiene partidos generados.', $group->name)],
            ]);
        }

        $hasUnfinishedGames = $group->games()
            ->where('status', '!=', GameStatus::Finished)
            ->exists();

        if ($hasUnfinishedGames) {
            throw ValidationException::withMessages([
                'group' => [sprintf('El grupo "%s" todavía tiene partidos sin finalizar.', $group->name)],
            ]);
        }

        $standingsResult = $this->groupStandingsCalculator->calculate($group);
        $standings = $standingsResult->standings;

        $availableQualifiers = min($qualifiersPerGroup, $standings->count());
        $groupQualifiers = $standings->take($availableQualifiers);

        if (
            $standingsResult->requiresManualTiebreak()
            && $this->manualTieCrossesQualifierCutoff(
                standings: $standings,
                manualTiebreakGroups: $standingsResult->manualTiebreakGroups,
                qualifierCutoff: $availableQualifiers,
            )
        ) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    sprintf(
                        'El grupo "%s" requiere desempate manual para definir la clasificación.',
                        $group->name
                    ),
                ],
            ]);
        }

        return $groupQualifiers;
    }

    /**
     * @param  Collection<int, CompetitionStandingData>  $standings
     * @param  array<int, array{player_ids: array<int, int>, player_names: array<int, string>}>  $manualTiebreakGroups
     */
    private function manualTieCrossesQualifierCutoff(
        Collection $standings,
        array $manualTiebreakGroups,
        int $qualifierCutoff,
    ): bool {
        if ($qualifierCutoff <= 0) {
            return false;
        }

        $positionByPlayerId = $standings
            ->values()
            ->mapWithKeys(fn (CompetitionStandingData $standing, int $index): array => [
                $standing->playerId => $index,
            ])
            ->all();

        foreach ($manualTiebreakGroups as $manualTiebreakGroup) {
            $positions = collect($manualTiebreakGroup['player_ids'] ?? [])
                ->map(fn (int $playerId): ?int => $positionByPlayerId[$playerId] ?? null)
                ->filter(fn (?int $position): bool => $position !== null)
                ->values();

            if ($positions->isEmpty()) {
                continue;
            }

            $minPosition = (int) $positions->min();
            $maxPosition = (int) $positions->max();

            if ($minPosition < $qualifierCutoff && $maxPosition >= $qualifierCutoff) {
                return true;
            }
        }

        return false;
    }
}
