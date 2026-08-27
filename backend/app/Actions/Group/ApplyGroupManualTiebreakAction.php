<?php

namespace App\Actions\Group;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\ManualTiebreakReason;
use App\Models\Group;
use App\Models\GroupManualTiebreak;
use App\Models\GroupManualTiebreakEntry;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\BuildSinglesEntryIndexForGroup;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Group\GroupStandingsCalculator;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApplyGroupManualTiebreakAction
{
    public function __construct(
        private readonly GroupStandingsCalculator $groupStandingsCalculator,
        private readonly BuildSinglesEntryIndexForGroup $buildSinglesEntryIndexForGroup,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{player_ids: array<int, int>, reason: ManualTiebreakReason, notes?: ?string}  $payload
     */
    public function __invoke(Group $group, array $payload): GroupManualTiebreak
    {
        $group->loadMissing('competition.tournament');
        TournamentLifecycleGuard::ensureMutableForGroup($group);

        CompetitionFormatGuard::ensureGroupStage($group->competition);

        if ($group->competition->brackets()->exists()) {
            throw ValidationException::withMessages([
                'group' => ['No se puede definir desempate manual cuando ya existe un cuadro eliminatorio.'],
            ]);
        }

        if (! $this->groupStandingsCalculator->isGroupComplete($group)) {
            throw ValidationException::withMessages([
                'group' => ['El desempate manual solo puede definirse cuando todos los partidos del grupo estén finalizados.'],
            ]);
        }

        $playerIds = array_values(array_map('intval', $payload['player_ids']));

        if (count($playerIds) !== count(array_unique($playerIds))) {
            throw ValidationException::withMessages([
                'player_ids' => ['No se permiten jugadores duplicados.'],
            ]);
        }

        $index = ($this->buildSinglesEntryIndexForGroup)($group);
        $entryIds = [];

        foreach ($playerIds as $playerId) {
            $entryId = $index->entryIdForPlayer($playerId);

            if ($entryId === null) {
                throw ValidationException::withMessages([
                    'player_ids' => ['Uno o más jugadores no pertenecen al grupo.'],
                ]);
            }

            $entryIds[] = $entryId;
        }

        $automaticResult = $this->groupStandingsCalculator->calculateAutomaticOnly($group);
        $pendingGroups = $automaticResult->pendingManualTieEntryGroups;

        if ($pendingGroups === []) {
            throw ValidationException::withMessages([
                'player_ids' => ['No hay empates pendientes de definición manual en este grupo.'],
            ]);
        }

        if (! $this->matchesPendingGroup($entryIds, $pendingGroups)) {
            throw ValidationException::withMessages([
                'player_ids' => ['Los jugadores enviados no coinciden con un empate pendiente actual.'],
            ]);
        }

        $existingTiebreak = $this->findExistingTiebreak($group, $entryIds);
        $oldOrderedPlayerIds = $existingTiebreak instanceof GroupManualTiebreak
            ? $index->playerIdsForEntries($existingTiebreak->orderedCompetitionEntryIds())
            : [];

        return DB::transaction(function () use (
            $group,
            $playerIds,
            $entryIds,
            $payload,
            $oldOrderedPlayerIds,
            $index,
        ): GroupManualTiebreak {
            $existingTiebreak = $this->findExistingTiebreak($group, $entryIds);

            if ($existingTiebreak instanceof GroupManualTiebreak) {
                $existingTiebreak->update([
                    'reason' => $payload['reason'],
                    'notes' => $payload['notes'] ?? null,
                    'applied_at' => now(),
                ]);

                $existingTiebreak->entries()->delete();
                $tiebreak = $existingTiebreak;
            } else {
                $tiebreak = GroupManualTiebreak::query()->create([
                    'group_id' => $group->id,
                    'reason' => $payload['reason'],
                    'notes' => $payload['notes'] ?? null,
                    'applied_at' => now(),
                ]);
            }

            foreach ($entryIds as $positionIndex => $entryId) {
                GroupManualTiebreakEntry::query()->create([
                    'group_manual_tiebreak_id' => $tiebreak->id,
                    'competition_entry_id' => $entryId,
                    'position' => $positionIndex + 1,
                ]);
            }

            $tiebreak->load(['entries.competitionEntry.members.player:id,first_name,last_name']);

            $oldPayload = $oldOrderedPlayerIds !== []
                ? ['ordered_player_ids' => $oldOrderedPlayerIds]
                : [];

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GROUP_MANUAL_TIEBREAK_APPLIED,
                logName: 'groups',
                subject: $group,
                context: AuditContextBuilder::fromGroup($group),
                old: $oldPayload,
                new: [
                    'ordered_player_ids' => $playerIds,
                ],
                summary: [
                    'positions_affected' => range(1, count($playerIds)),
                    'players' => collect($playerIds)
                        ->map(function (int $playerId) use ($index): array {
                            $entryId = $index->entryIdForPlayer($playerId);

                            return [
                                'id' => $playerId,
                                'name' => $entryId !== null
                                    ? $index->playerNameForEntry($entryId)
                                    : '',
                            ];
                        })
                        ->values()
                        ->all(),
                ],
                reason: $payload['notes'] ?? null,
            ));

            return $tiebreak;
        });
    }

    /**
     * @param  array<int, int>  $entryIds
     * @param  array<int, array<int, int>>  $pendingGroups
     */
    private function matchesPendingGroup(array $entryIds, array $pendingGroups): bool
    {
        foreach ($pendingGroups as $pendingGroup) {
            if ($this->entrySetsMatch($entryIds, $pendingGroup)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, int>  $left
     * @param  array<int, int>  $right
     */
    private function entrySetsMatch(array $left, array $right): bool
    {
        $leftSorted = array_map('intval', $left);
        $rightSorted = array_map('intval', $right);
        sort($leftSorted);
        sort($rightSorted);

        return $leftSorted === $rightSorted;
    }

    /**
     * @param  array<int, int>  $entryIds
     */
    private function findExistingTiebreak(Group $group, array $entryIds): ?GroupManualTiebreak
    {
        $tiebreaks = $group->manualTiebreaks()
            ->with('entries')
            ->get();

        foreach ($tiebreaks as $tiebreak) {
            if ($this->entrySetsMatch($tiebreak->orderedCompetitionEntryIds(), $entryIds)) {
                return $tiebreak;
            }
        }

        return null;
    }
}
