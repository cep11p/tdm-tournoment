<?php

namespace App\Actions\Group;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\CompetitionType;
use App\Enums\ManualTiebreakReason;
use App\Models\Group;
use App\Models\GroupManualTiebreak;
use App\Models\GroupManualTiebreakEntry;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\BuildGroupEntryIndexForGroup;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Group\GroupStandingsResolver;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApplyGroupManualTiebreakAction
{
    public function __construct(
        private readonly GroupStandingsResolver $groupStandingsResolver,
        private readonly BuildGroupEntryIndexForGroup $buildGroupEntryIndexForGroup,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{entry_ids: array<int, int>, reason: ManualTiebreakReason, notes?: ?string, validation_error_key?: string}  $payload
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

        if (! $this->groupStandingsResolver->isGroupComplete($group)) {
            $group->loadMissing('competition');
            $type = $group->competition?->type instanceof CompetitionType
                ? $group->competition->type
                : CompetitionType::tryFrom((string) $group->competition?->type);
            $scheduleLabel = $type === CompetitionType::Team ? 'enfrentamientos' : 'partidos';

            throw ValidationException::withMessages([
                'group' => [sprintf(
                    'El desempate manual solo puede definirse cuando todos los %s del grupo estén finalizados.',
                    $scheduleLabel,
                )],
            ]);
        }

        $entryIds = array_values(array_map('intval', $payload['entry_ids']));
        $validationErrorKey = $payload['validation_error_key'] ?? 'entry_ids';

        if (count($entryIds) !== count(array_unique($entryIds))) {
            throw ValidationException::withMessages([
                $validationErrorKey => ['No se permiten participaciones duplicadas.'],
            ]);
        }

        $index = ($this->buildGroupEntryIndexForGroup)($group);

        foreach ($entryIds as $entryId) {
            if (! in_array($entryId, $index->entryIds(), true)) {
                throw ValidationException::withMessages([
                    $validationErrorKey => ['Una o más participaciones no pertenecen al grupo.'],
                ]);
            }
        }

        $automaticResult = $this->groupStandingsResolver->calculateAutomaticOnly($group);
        $pendingGroups = $automaticResult->pendingManualTieEntryGroups;

        if ($pendingGroups === []) {
            throw ValidationException::withMessages([
                $validationErrorKey => ['No hay empates pendientes de definición manual en este grupo.'],
            ]);
        }

        if (! $this->matchesPendingGroup($entryIds, $pendingGroups)) {
            throw ValidationException::withMessages([
                $validationErrorKey => ['Las participaciones enviadas no coinciden con un empate pendiente actual.'],
            ]);
        }

        $existingTiebreak = $this->findExistingTiebreak($group, $entryIds);
        $oldOrderedEntryIds = $existingTiebreak instanceof GroupManualTiebreak
            ? $existingTiebreak->orderedCompetitionEntryIds()
            : [];

        return DB::transaction(function () use (
            $group,
            $entryIds,
            $payload,
            $oldOrderedEntryIds,
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

            $oldPayload = $oldOrderedEntryIds !== []
                ? ['ordered_entry_ids' => $oldOrderedEntryIds]
                : [];

            $newPayload = [
                'ordered_entry_ids' => $entryIds,
                'display_names' => $index->displayNamesForEntries($entryIds),
            ];

            if ($index->isSingles()) {
                $oldPayload = $oldOrderedEntryIds !== []
                    ? [
                        ...$oldPayload,
                        'ordered_player_ids' => $index->playerIdsForEntries($oldOrderedEntryIds),
                    ]
                    : $oldPayload;

                $newPayload['ordered_player_ids'] = $index->playerIdsForEntries($entryIds);
            }

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GROUP_MANUAL_TIEBREAK_APPLIED,
                logName: 'groups',
                subject: $group,
                context: AuditContextBuilder::fromGroup($group),
                old: $oldPayload,
                new: $newPayload,
                summary: [
                    'positions_affected' => range(1, count($entryIds)),
                    'entries' => collect($entryIds)
                        ->map(fn (int $entryId): array => [
                            'id' => $entryId,
                            'name' => $index->displayNameForEntry($entryId),
                        ])
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
