<?php

namespace App\Actions\Bracket;

use App\Actions\Game\CreateGameAction;
use App\Data\Audit\AuditEntry;
use App\Data\Bracket\GroupKnockoutDrawResult;
use App\Data\Competition\GroupQualifierData;
use App\Enums\AuditAction;
use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\Game;
use App\Models\TeamTie;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Bracket\BracketSupport;
use App\Support\Bracket\GroupKnockoutDrawBuilder;
use App\Support\Bracket\GroupKnockoutDrawTemplateCatalog;
use App\Support\Bracket\GroupKnockoutDrawTemplateResolver;
use App\Support\Bracket\GroupQualifierCanonicalOrder;
use App\Support\Bracket\GroupQualifiersCollector;
use App\Support\Competition\CompetitionParticipantLabel;
use App\Support\Game\GameFormatResolver;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateBracketKnockoutAction
{
    public function __construct(
        private readonly CreateGameAction $createGame,
        private readonly CreateBracketTeamTieAction $createBracketTeamTie,
        private readonly CreateBracketEntryOriginsAction $createBracketEntryOrigins,
        private readonly GroupQualifiersCollector $groupQualifiersCollector,
        private readonly GroupKnockoutDrawBuilder $groupKnockoutDrawBuilder,
        private readonly GroupKnockoutDrawTemplateResolver $groupKnockoutDrawTemplateResolver,
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

        if ($competition->isTeam()) {
            if (
                TeamTie::query()
                    ->where('competition_id', $competition->id)
                    ->whereNotNull('group_id')
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'competition' => ['La competencia de eliminación directa no puede tener enfrentamientos de grupo.'],
                ]);
            }
        } elseif (
            Game::query()
                ->where('competition_id', $competition->id)
                ->whereNotNull('group_id')
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'competition' => ['La competencia de eliminación directa no puede tener partidos de grupo.'],
            ]);
        }

        $entryIds = $competition->entries()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($entryId) => (int) $entryId)
            ->all();

        if (count($entryIds) < 2) {
            throw ValidationException::withMessages([
                'competition' => [
                    CompetitionParticipantLabel::minimumForGroups($competition),
                ],
            ]);
        }

        return $this->buildBracketFromEntryIds(
            competition: $competition,
            entryIds: $entryIds,
            firstRoundSlots: BracketSupport::firstRoundSlots($entryIds),
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
            $groupCount = GroupQualifierCanonicalOrder::groups($groupQualifiers)->count();

            if (GroupKnockoutDrawTemplateCatalog::supports($groupCount)) {
                $draw = $this->groupKnockoutDrawTemplateResolver->resolve(
                    GroupKnockoutDrawTemplateCatalog::forGroupCount($groupCount),
                    $groupQualifiers,
                );

                return $this->buildBracketFromDrawResult(
                    competition: $competition,
                    draw: $draw,
                    qualifiersPerGroup: $qualifiersPerGroup,
                    payload: $payload,
                    groupQualifiers: $groupQualifiers,
                );
            }

            if ($this->groupKnockoutDrawBuilder->canBuildPlayInDraw($groupQualifiers, $qualifiersPerGroup)) {
                $draw = $this->groupKnockoutDrawBuilder->buildDraw($groupQualifiers, $qualifiersPerGroup);

                return $this->buildBracketFromDrawResult(
                    competition: $competition,
                    draw: $draw,
                    qualifiersPerGroup: $qualifiersPerGroup,
                    payload: $payload,
                    groupQualifiers: $groupQualifiers,
                );
            }

            $entryIds = $this->groupKnockoutDrawBuilder->buildDirectEntryIds(
                $groupQualifiers,
                $qualifiersPerGroup,
            );

            if (count($entryIds) < 2) {
                throw ValidationException::withMessages([
                    'qualified_per_group' => [
                        'Se requieren al menos 2 clasificados para generar el cuadro eliminatorio.',
                    ],
                ]);
            }

            return $this->buildBracketFromEntryIds(
                competition: $competition,
                entryIds: $entryIds,
                firstRoundSlots: BracketSupport::firstRoundSlots($entryIds),
                qualifiersPerGroup: $qualifiersPerGroup,
                payload: $payload,
                groupQualifiers: $groupQualifiers,
            );
        }

        if ($qualifiersPerGroup === 2) {
            $entryIds = $this->groupKnockoutDrawBuilder->build($groupQualifiers, $qualifiersPerGroup);

            if (count($entryIds) < 2) {
                throw ValidationException::withMessages([
                    'qualified_per_group' => [
                        'Se requieren al menos 2 clasificados para generar el cuadro eliminatorio.',
                    ],
                ]);
            }

            return $this->buildBracketFromEntryIds(
                competition: $competition,
                entryIds: $entryIds,
                firstRoundSlots: $this->adjacentFoldFirstRoundSlots($entryIds),
                qualifiersPerGroup: $qualifiersPerGroup,
                payload: $payload,
                groupQualifiers: $groupQualifiers,
            );
        }

        $entryIds = $this->legacyGlobalSeededEntryIds($groupQualifiers);

        if (count($entryIds) < 2) {
            throw ValidationException::withMessages([
                'qualified_per_group' => [
                    'Se requieren al menos 2 clasificados para generar el cuadro eliminatorio.',
                ],
            ]);
        }

        return $this->buildBracketFromEntryIds(
            competition: $competition,
            entryIds: $entryIds,
            firstRoundSlots: BracketSupport::firstRoundSlots($entryIds),
            qualifiersPerGroup: $qualifiersPerGroup,
            payload: $payload,
            groupQualifiers: $groupQualifiers,
        );
    }

    /**
     * @param  Collection<int, GroupQualifierData>  $groupQualifiers
     * @return array<int, int>
     */
    private function legacyGlobalSeededEntryIds($groupQualifiers): array
    {
        return $groupQualifiers
            ->sort(function (GroupQualifierData $left, GroupQualifierData $right): int {
                return [$right->won, $left->lost, strtolower($left->displayName)]
                    <=>
                    [$left->won, $right->lost, strtolower($right->displayName)];
            })
            ->pluck('competitionEntryId')
            ->map(fn (int $entryId) => $entryId)
            ->values()
            ->all();
    }

    /**
     * Pairing Q2: seed k vs seed (size - k + 1), pensado para el draw grupal.
     *
     * @param  array<int, int>  $entryIds
     * @return list<array{bracketMatch: int, entry1Id: int, entry2Id: int|null, isBye: bool}>
     */
    private function adjacentFoldFirstRoundSlots(array $entryIds): array
    {
        $qualifierCount = count($entryIds);
        $bracketSize = BracketSupport::nextPowerOfTwo($qualifierCount);
        $matchCount = (int) ($bracketSize / 2);
        $slots = [];

        for ($matchIndex = 0; $matchIndex < $matchCount; $matchIndex++) {
            $topSeed = $matchIndex + 1;
            $bottomSeed = $bracketSize - $matchIndex;
            $bottomEntryId = $bottomSeed <= $qualifierCount
                ? $entryIds[$bottomSeed - 1]
                : null;

            $slots[] = [
                'bracketMatch' => $matchIndex + 1,
                'entry1Id' => $entryIds[$topSeed - 1],
                'entry2Id' => $bottomEntryId,
                'isBye' => $bottomEntryId === null,
            ];
        }

        return $slots;
    }

    /**
     * @param  array<int, int>  $entryIds
     * @param  list<array{bracketMatch: int, entry1Id: int, entry2Id: int|null, isBye: bool}>  $firstRoundSlots
     * @param  Collection<int, GroupQualifierData>|null  $groupQualifiers
     */
    private function buildBracketFromEntryIds(
        Competition $competition,
        array $entryIds,
        array $firstRoundSlots,
        int $qualifiersPerGroup,
        array $payload,
        ?Collection $groupQualifiers = null,
    ): Bracket {
        $qualifierCount = count($entryIds);

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
            $name = 'Llave - '.$competition->name;
        }

        return DB::transaction(function () use (
            $competition,
            $entryIds,
            $firstRoundSlots,
            $qualifierCount,
            $bracketSize,
            $byesCount,
            $roundLabel,
            $matchFormat,
            $name,
            $qualifiersPerGroup,
            $groupQualifiers,
        ): Bracket {
            $bracket = Bracket::query()->create([
                'competition_id' => $competition->id,
                'name' => $name,
                'qualifiers_per_group' => $qualifiersPerGroup,
                'bracket_size' => $bracketSize,
                'byes_count' => $byesCount,
            ]);

            $this->persistGroupEntryOrigins($bracket, $groupQualifiers, $entryIds);

            foreach ($firstRoundSlots as $slot) {
                $topEntryId = $slot['entry1Id'];
                $bottomEntryId = $slot['entry2Id'];
                $bracketMatch = $slot['bracketMatch'];

                if ($competition->isTeam()) {
                    ($this->createBracketTeamTie)(
                        competition: $competition,
                        bracket: $bracket,
                        entry1Id: $topEntryId,
                        entry2Id: $bottomEntryId,
                        bracketRound: 1,
                        bracketMatch: $bracketMatch,
                        bracketPurpose: BracketGamePurpose::Main,
                        roundLabel: $roundLabel,
                    );

                    continue;
                }

                if ($slot['isBye']) {
                    ($this->createGame)([
                        'competition_id' => $competition->id,
                        'bracket_id' => $bracket->id,
                        'entry1_id' => $topEntryId,
                        'entry2_id' => null,
                        'winner_entry_id' => $topEntryId,
                        'status' => GameStatus::Finished,
                        'finished_at' => now(),
                        'is_bye' => true,
                        'round' => $roundLabel,
                        'bracket_round' => 1,
                        'bracket_match' => $bracketMatch,
                    ]);

                    continue;
                }

                ($this->createGame)([
                    'competition_id' => $competition->id,
                    'bracket_id' => $bracket->id,
                    'entry1_id' => $topEntryId,
                    'entry2_id' => $bottomEntryId,
                    'round' => $roundLabel,
                    'bracket_round' => 1,
                    'bracket_match' => $bracketMatch,
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
                matchesCreated: count($firstRoundSlots),
            );

            return $this->loadBracket($bracket);
        });
    }

    /**
     * @param  Collection<int, GroupQualifierData>  $groupQualifiers
     */
    private function buildBracketFromDrawResult(
        Competition $competition,
        GroupKnockoutDrawResult $draw,
        int $qualifiersPerGroup,
        array $payload,
        Collection $groupQualifiers,
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
            $name = 'Llave - '.$competition->name;
        }

        return DB::transaction(function () use (
            $competition,
            $draw,
            $matchFormat,
            $name,
            $qualifiersPerGroup,
            $groupQualifiers,
        ): Bracket {
            $bracket = Bracket::query()->create([
                'competition_id' => $competition->id,
                'name' => $name,
                'qualifiers_per_group' => $qualifiersPerGroup,
                'bracket_size' => $draw->bracketSize,
                'byes_count' => $draw->byesCount,
            ]);

            $this->persistGroupEntryOrigins(
                $bracket,
                $groupQualifiers,
                $this->usedEntryIdsFromDraw($draw),
            );

            foreach ($draw->matches as $match) {
                if ($competition->isTeam()) {
                    ($this->createBracketTeamTie)(
                        competition: $competition,
                        bracket: $bracket,
                        entry1Id: $match->entry1Id,
                        entry2Id: $match->isBye ? null : $match->entry2Id,
                        bracketRound: 1,
                        bracketMatch: $match->bracketMatch,
                        bracketPurpose: BracketGamePurpose::Main,
                        roundLabel: $draw->firstRoundLabel,
                    );

                    continue;
                }

                if ($match->isBye) {
                    ($this->createGame)([
                        'competition_id' => $competition->id,
                        'bracket_id' => $bracket->id,
                        'entry1_id' => $match->entry1Id,
                        'entry2_id' => null,
                        'winner_entry_id' => $match->entry1Id,
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
                    'entry1_id' => $match->entry1Id,
                    'entry2_id' => $match->entry2Id,
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
                matchesCreated: count($draw->matches),
            );

            return $this->loadBracket($bracket);
        });
    }

    /**
     * @param  Collection<int, GroupQualifierData>|null  $groupQualifiers
     * @param  array<int, int>  $entryIds
     */
    private function persistGroupEntryOrigins(
        Bracket $bracket,
        ?Collection $groupQualifiers,
        array $entryIds,
    ): void {
        if ($groupQualifiers === null) {
            return;
        }

        ($this->createBracketEntryOrigins)($bracket, $groupQualifiers, $entryIds);
    }

    /**
     * @return list<int>
     */
    private function usedEntryIdsFromDraw(GroupKnockoutDrawResult $draw): array
    {
        $entryIds = [];

        foreach ($draw->matches as $match) {
            $entryIds[] = $match->entry1Id;

            if (! $match->isBye && $match->entry2Id !== null) {
                $entryIds[] = $match->entry2Id;
            }
        }

        return $entryIds;
    }

    private function loadBracket(Bracket $bracket): Bracket
    {
        $bracket->loadMissing('competition');

        return $bracket->load(Bracket::overviewRelations($bracket->competition));
    }

    private function auditBracketCreated(
        Competition $competition,
        Bracket $bracket,
        int $qualifiedPlayers,
        int $bracketSize,
        int $byesCount,
        int $matchesCreated,
    ): void {
        $summary = [
            'qualified_players' => $qualifiedPlayers,
            'bracket_size' => $bracketSize,
            'byes_count' => $byesCount,
            'games_created' => $matchesCreated,
        ];

        if ($competition->isTeam()) {
            $summary['match_entity'] = 'team_tie';
            $summary['team_ties_created'] = $matchesCreated;
        }

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
            summary: $summary,
        ));
    }
}
