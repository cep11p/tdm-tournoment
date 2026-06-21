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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateBracketKnockoutAction
{
    public function __construct(
        private readonly CreateGameAction $createGame,
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
        $name = $payload['name'] ?? 'Eliminatoria';

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

        $statsByPlayer = $this->initializeStats($groupPlayers);

        foreach ($group->games()->where('status', GameStatus::Finished)->whereNotNull('winner_id')->get() as $game) {
            $winnerId = (int) $game->winner_id;
            $loserId = $winnerId === (int) $game->player1_id
                ? (int) $game->player2_id
                : (int) $game->player1_id;

            if (isset($statsByPlayer[$winnerId])) {
                $statsByPlayer[$winnerId]['won']++;
            }

            if (isset($statsByPlayer[$loserId])) {
                $statsByPlayer[$loserId]['lost']++;
            }
        }

        $standings = $groupPlayers
            ->map(function ($groupPlayer) use ($statsByPlayer): CompetitionStandingData {
                $playerId = (int) $groupPlayer->player_id;
                $stats = $statsByPlayer[$playerId] ?? ['won' => 0, 'lost' => 0];

                return new CompetitionStandingData(
                    playerId: $playerId,
                    playerName: trim(sprintf(
                        '%s %s',
                        (string) $groupPlayer->player?->first_name,
                        (string) $groupPlayer->player?->last_name
                    )),
                    won: (int) $stats['won'],
                    lost: (int) $stats['lost'],
                );
            })
            ->sort(function (CompetitionStandingData $left, CompetitionStandingData $right): int {
                return [$right->won, $left->lost, strtolower($left->playerName)]
                    <=>
                    [$left->won, $right->lost, strtolower($right->playerName)];
            })
            ->values();

        $availableQualifiers = min($qualifiersPerGroup, $standings->count());
        $groupQualifiers = $standings->take($availableQualifiers);

        if ($standings->count() > $availableQualifiers) {
            $lastQualifier = $groupQualifiers->last();
            $firstExcluded = $standings->get($availableQualifiers);

            if (
                $lastQualifier instanceof CompetitionStandingData
                && $firstExcluded instanceof CompetitionStandingData
                && $lastQualifier->won === $firstExcluded->won
                && $lastQualifier->lost === $firstExcluded->lost
            ) {
                throw ValidationException::withMessages([
                    'qualified_per_group' => [
                        sprintf('Hay empate en la clasificación del grupo "%s".', $group->name),
                    ],
                ]);
            }
        }

        return $groupQualifiers;
    }

    /**
     * @return array<int, array{won: int, lost: int}>
     */
    private function initializeStats(Collection $groupPlayers): array
    {
        $stats = [];

        foreach ($groupPlayers as $groupPlayer) {
            $stats[(int) $groupPlayer->player_id] = [
                'won' => 0,
                'lost' => 0,
            ];
        }

        return $stats;
    }
}
