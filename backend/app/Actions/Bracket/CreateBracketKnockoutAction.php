<?php

namespace App\Actions\Bracket;

use App\Actions\Game\CreateGameAction;
use App\Data\Audit\AuditEntry;
use App\Data\Bracket\GroupKnockoutDrawResult;
use App\Data\Competition\GroupQualifierData;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\Game;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Tournament\TournamentLifecycleGuard;
use App\Support\Bracket\BracketSupport;
use App\Support\Bracket\GroupKnockoutDrawBuilder;
use App\Support\Bracket\GroupQualifiersCollector;
use App\Support\Game\GameFormatResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateBracketKnockoutAction
{
    public function __construct(
        private readonly CreateGameAction $createGame,
        private readonly GroupQualifiersCollector $groupQualifiersCollector,
        private readonly GroupKnockoutDrawBuilder $groupKnockoutDrawBuilder,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(Competition $competition, array $payload): Bracket
    {
        $competition->loadMissing('tournament');
        TournamentLifecycleGuard::ensureMutableForCompetition($competition);

        if ($competition->brackets()->exists()) {
            throw ValidationException::withMessages([
                'competition' => ['La competencia ya tiene un cuadro eliminatorio.'],
            ]);
        }

        if ($competition->format->isKnockoutDirect()) {
            return $this->createDirectKnockoutBracket($competition, $payload);
        }

        return $this->createGroupsKnockoutBracket($competition, $payload);
    }

    private function createDirectKnockoutBracket(Competition $competition, array $payload): Bracket
    {
        if ($competition->groups()->exists()) {
            throw ValidationException::withMessages([
                'competition' => ['La competencia de eliminación directa no puede tener grupos.'],
            ]);
        }

        if (
            Game::query()
                ->where('competition_id', $competition->id)
                ->whereNotNull('group_id')
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'competition' => ['La competencia de eliminación directa no puede tener partidos de grupo.'],
            ]);
        }

        $playerIds = $competition->registrations()
            ->orderBy('player_id')
            ->pluck('player_id')
            ->map(fn ($playerId) => (int) $playerId)
            ->values()
            ->all();

        if (count($playerIds) < 2) {
            throw ValidationException::withMessages([
                'competition' => [
                    'Se requieren al menos 2 jugadores inscriptos para generar el cuadro eliminatorio.',
                ],
            ]);
        }

        return $this->buildBracketFromPlayerIds(
            competition: $competition,
            playerIds: $playerIds,
            qualifiersPerGroup: 0,
            payload: $payload,
        );
    }

    private function createGroupsKnockoutBracket(Competition $competition, array $payload): Bracket
    {
        if (! $competition->groups()->exists()) {
            throw ValidationException::withMessages([
                'competition' => ['La competencia no tiene grupos.'],
            ]);
        }

        $qualifiersPerGroup = (int) $competition->qualified_per_group;
        $groupQualifiers = $this->groupQualifiersCollector->collect($competition);

        if ($qualifiersPerGroup === 3) {
            if ($this->groupKnockoutDrawBuilder->canBuildPlayInDraw($groupQualifiers, $qualifiersPerGroup)) {
                $draw = $this->groupKnockoutDrawBuilder->buildDraw($groupQualifiers, $qualifiersPerGroup);

                return $this->buildBracketFromDrawResult(
                    competition: $competition,
                    draw: $draw,
                    qualifiersPerGroup: $qualifiersPerGroup,
                    payload: $payload,
                );
            }

            $playerIds = $this->groupKnockoutDrawBuilder->buildDirectPlayerIds(
                $groupQualifiers,
                $qualifiersPerGroup,
            );

            if (count($playerIds) < 2) {
                throw ValidationException::withMessages([
                    'qualified_per_group' => [
                        'Se requieren al menos 2 clasificados para generar el cuadro eliminatorio.',
                    ],
                ]);
            }

            return $this->buildBracketFromPlayerIds(
                competition: $competition,
                playerIds: $playerIds,
                qualifiersPerGroup: $qualifiersPerGroup,
                payload: $payload,
            );
        }

        $playerIds = $qualifiersPerGroup === 2
            ? $this->groupKnockoutDrawBuilder->build($groupQualifiers, $qualifiersPerGroup)
            : $this->legacyGlobalSeededPlayerIds($groupQualifiers);

        if (count($playerIds) < 2) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    'Se requieren al menos 2 clasificados para generar el cuadro eliminatorio.',
                ],
            ]);
        }

        return $this->buildBracketFromPlayerIds(
            competition: $competition,
            playerIds: $playerIds,
            qualifiersPerGroup: $qualifiersPerGroup,
            payload: $payload,
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, GroupQualifierData>  $groupQualifiers
     * @return array<int, int>
     */
    private function legacyGlobalSeededPlayerIds($groupQualifiers): array
    {
        return $groupQualifiers
            ->sort(function (GroupQualifierData $left, GroupQualifierData $right): int {
                return [$right->won, $left->lost, strtolower($left->playerName)]
                    <=>
                    [$left->won, $right->lost, strtolower($right->playerName)];
            })
            ->pluck('playerId')
            ->map(fn (int $playerId) => $playerId)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $playerIds
     */
    private function buildBracketFromPlayerIds(
        Competition $competition,
        array $playerIds,
        int $qualifiersPerGroup,
        array $payload,
    ): Bracket {
        $qualifierCount = count($playerIds);

        $bracketSize = BracketSupport::nextPowerOfTwo($qualifierCount);

        if ($bracketSize > BracketSupport::MAX_BRACKET_SIZE) {
            $errorField = $competition->format->isKnockoutDirect()
                ? 'competition'
                : 'qualified_per_group';

            throw ValidationException::withMessages([
                $errorField => [
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
            $playerIds,
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

            $this->auditBracketCreated(
                competition: $competition,
                bracket: $bracket,
                qualifiedPlayers: $qualifierCount,
                bracketSize: $bracketSize,
                byesCount: $byesCount,
                gamesCreated: $matchCount,
            );

            return $bracket->load([
                'games.player1:id,first_name,last_name,nickname',
                'games.player2:id,first_name,last_name,nickname',
                'games.winner:id,first_name,last_name,nickname',
                'games.sets',
            ]);
        });
    }

    private function buildBracketFromDrawResult(
        Competition $competition,
        GroupKnockoutDrawResult $draw,
        int $qualifiersPerGroup,
        array $payload,
    ): Bracket {
        if ($draw->bracketSize > BracketSupport::MAX_BRACKET_SIZE) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    sprintf(
                        'El cuadro eliminatorio admite hasta %d clasificados. La configuración actual produce %d.',
                        BracketSupport::MAX_BRACKET_SIZE,
                        $draw->bracketSize - $draw->byesCount,
                    ),
                ],
            ]);
        }

        $matchFormat = GameFormatResolver::resolveForBracketRound($competition, $draw->firstRoundLabel);
        $name = trim($payload['name'] ?? '');

        if ($name === '') {
            $name = 'Llave - ' . $competition->name;
        }

        return DB::transaction(function () use (
            $competition,
            $draw,
            $matchFormat,
            $name,
            $qualifiersPerGroup
        ): Bracket {
            $bracket = Bracket::query()->create([
                'competition_id' => $competition->id,
                'name' => $name,
                'qualifiers_per_group' => $qualifiersPerGroup,
                'bracket_size' => $draw->bracketSize,
                'byes_count' => $draw->byesCount,
            ]);

            foreach ($draw->matches as $match) {
                if ($match->isBye) {
                    ($this->createGame)([
                        'competition_id' => $competition->id,
                        'bracket_id' => $bracket->id,
                        'player1_id' => $match->player1Id,
                        'player2_id' => null,
                        'winner_id' => $match->player1Id,
                        'status' => GameStatus::Finished,
                        'finished_at' => now(),
                        'is_bye' => true,
                        'round' => $draw->firstRoundLabel,
                        'bracket_round' => 1,
                        'bracket_match' => $match->bracketMatch,
                    ]);

                    continue;
                }

                ($this->createGame)([
                    'competition_id' => $competition->id,
                    'bracket_id' => $bracket->id,
                    'player1_id' => $match->player1Id,
                    'player2_id' => $match->player2Id,
                    'round' => $draw->firstRoundLabel,
                    'bracket_round' => 1,
                    'bracket_match' => $match->bracketMatch,
                    'is_bye' => false,
                    'best_of' => $matchFormat['best_of'],
                    'sets_to_win' => $matchFormat['sets_to_win'],
                ]);
            }

            $this->auditBracketCreated(
                competition: $competition,
                bracket: $bracket,
                qualifiedPlayers: $draw->bracketSize - $draw->byesCount,
                bracketSize: $draw->bracketSize,
                byesCount: $draw->byesCount,
                gamesCreated: count($draw->matches),
            );

            return $bracket->load([
                'games.player1:id,first_name,last_name,nickname',
                'games.player2:id,first_name,last_name,nickname',
                'games.winner:id,first_name,last_name,nickname',
                'games.sets',
            ]);
        });
    }

    private function auditBracketCreated(
        Competition $competition,
        Bracket $bracket,
        int $qualifiedPlayers,
        int $bracketSize,
        int $byesCount,
        int $gamesCreated,
    ): void {
        $this->auditLogger->log(new AuditEntry(
            action: AuditAction::BRACKET_CREATED,
            logName: 'bracket',
            subject: $competition,
            context: AuditContextBuilder::fromCompetition($competition, $bracket->id),
            new: [
                'bracket_id' => $bracket->id,
                'bracket_size' => $bracketSize,
                'round' => 1,
            ],
            summary: [
                'qualified_players' => $qualifiedPlayers,
                'bracket_size' => $bracketSize,
                'byes_count' => $byesCount,
                'games_created' => $gamesCreated,
            ],
        ));
    }
}
