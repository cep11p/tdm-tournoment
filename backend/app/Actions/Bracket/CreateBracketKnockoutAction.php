<?php

namespace App\Actions\Bracket;

use App\Actions\Game\CreateGameAction;
use App\Data\Competition\CompetitionStandingData;
use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\Group;
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

        $qualifiersPerGroup = (int) $payload['qualifiers_per_group'];
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

        if (! in_array($qualifierCount, [2, 4, 8], true)) {
            throw ValidationException::withMessages([
                'qualifiers_per_group' => [
                    sprintf(
                        'Se requieren 2, 4 u 8 clasificados en total. La configuración actual produce %d.',
                        $qualifierCount
                    ),
                ],
            ]);
        }

        $roundLabel = $this->roundLabelFor($qualifierCount);
        $name = $payload['name'] ?? 'Eliminatoria';

        return DB::transaction(function () use (
            $competition,
            $seededQualifiers,
            $qualifierCount,
            $roundLabel,
            $name,
            $qualifiersPerGroup
        ): Bracket {
            $bracket = Bracket::query()->create([
                'competition_id' => $competition->id,
                'name' => $name,
                'qualifiers_per_group' => $qualifiersPerGroup,
            ]);

            $playerIds = $seededQualifiers
                ->pluck('playerId')
                ->map(fn (int $playerId) => $playerId)
                ->all();

            $matchCount = (int) ($qualifierCount / 2);

            for ($matchIndex = 0; $matchIndex < $matchCount; $matchIndex++) {
                ($this->createGame)([
                    'competition_id' => $competition->id,
                    'bracket_id' => $bracket->id,
                    'player1_id' => $playerIds[$matchIndex],
                    'player2_id' => $playerIds[$qualifierCount - 1 - $matchIndex],
                    'round' => $roundLabel,
                    'bracket_round' => 1,
                    'bracket_match' => $matchIndex + 1,
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
                    'qualifiers_per_group' => [
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

    private function roundLabelFor(int $qualifierCount): string
    {
        return match ($qualifierCount) {
            2 => 'Final',
            4 => 'Semifinal',
            8 => 'Cuartos de final',
        };
    }
}
