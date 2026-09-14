<?php

namespace App\Actions\GroupPlayer;

use App\Actions\Group\ReconcileGroupRoundRobinGamesAction;
use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Group;
use App\Models\GroupEntry;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Competition\LateGroupMutationGuard;
use App\Support\Competition\TeamCompetitionSchedulingGuard;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MoveCompetitionEntryBetweenGroupsAction
{
    public const SAME_GROUP_MESSAGE = 'El grupo origen y destino deben ser diferentes.';

    public const DIFFERENT_COMPETITION_MESSAGE = 'El grupo destino pertenece a otra competencia.';

    public const NOT_IN_SOURCE_MESSAGE = 'El participante no pertenece al grupo origen.';

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ReconcileGroupRoundRobinGamesAction $reconcileRoundRobin,
    ) {}

    public function __invoke(Group $sourceGroup, int $competitionEntryId, int $targetGroupId): GroupEntry
    {
        $sourceGroup->loadMissing('competition.tournament');

        if ((int) $sourceGroup->id === $targetGroupId) {
            throw ValidationException::withMessages([
                'target_group_id' => [self::SAME_GROUP_MESSAGE],
            ]);
        }

        $targetGroup = Group::query()->findOrFail($targetGroupId);
        $targetGroup->loadMissing('competition.tournament');

        TournamentLifecycleGuard::ensureMutableForGroup($sourceGroup);
        CompetitionFormatGuard::ensureGroupStage($sourceGroup->competition);
        TeamCompetitionSchedulingGuard::ensureGamesRoundRobinAllowed($sourceGroup->competition);
        LateGroupMutationGuard::ensureAllowed($sourceGroup->competition);

        if ((int) $targetGroup->competition_id !== (int) $sourceGroup->competition_id) {
            throw ValidationException::withMessages([
                'target_group_id' => [self::DIFFERENT_COMPETITION_MESSAGE],
            ]);
        }

        return DB::transaction(function () use ($sourceGroup, $targetGroup, $competitionEntryId): GroupEntry {
            [$lockedSource, $lockedTarget] = $this->lockGroupsForMove($sourceGroup, $targetGroup);

            $groupEntry = $lockedSource->groupEntries->first(
                fn (GroupEntry $entry): bool => (int) $entry->competition_entry_id === $competitionEntryId,
            );

            if (! $groupEntry instanceof GroupEntry) {
                throw ValidationException::withMessages([
                    'competition_entry_id' => [self::NOT_IN_SOURCE_MESSAGE],
                ]);
            }

            $groupEntry->load([
                'competitionEntry.members.player:id,first_name,last_name,nickname',
                'competitionEntry.competition',
            ]);
            $entryContext = AuditContextBuilder::fromGroupEntry($groupEntry);
            $sourceContext = AuditContextBuilder::fromGroup($lockedSource);

            $groupEntry->group_id = $lockedTarget->id;
            $groupEntry->save();

            ($this->reconcileRoundRobin)->reconcileLocked($lockedSource);
            ($this->reconcileRoundRobin)->reconcileLocked($lockedTarget);

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GROUP_PLAYER_MOVED,
                logName: 'groups',
                subject: $lockedSource,
                context: array_merge($sourceContext, $entryContext, [
                    'source_group_id' => $lockedSource->id,
                    'source_group_name' => $lockedSource->name,
                    'target_group_id' => $lockedTarget->id,
                    'target_group_name' => $lockedTarget->name,
                ]),
                old: [
                    'group_id' => $lockedSource->id,
                    'group_name' => $lockedSource->name,
                    'competition_entry_id' => $competitionEntryId,
                    ...$entryContext,
                ],
                new: [
                    'group_id' => $lockedTarget->id,
                    'group_name' => $lockedTarget->name,
                    'competition_entry_id' => $competitionEntryId,
                    ...$entryContext,
                ],
                summary: [
                    'competition_entry_id' => $competitionEntryId,
                    'source_group_id' => $lockedSource->id,
                    'source_group_name' => $lockedSource->name,
                    'target_group_id' => $lockedTarget->id,
                    'target_group_name' => $lockedTarget->name,
                    ...$entryContext,
                ],
            ));

            return $groupEntry->fresh([
                'competitionEntry.members.player:id,first_name,last_name,nickname',
                'competitionEntry.competition',
            ]) ?? $groupEntry;
        });
    }

    /**
     * Toma locks en orden total por group id ASC para evitar deadlocks
     * entre movimientos concurrentes A→B y B→A:
     * Group menor → Group mayor → GroupEntries/Games de cada uno en ese mismo orden.
     *
     * @return array{0: Group, 1: Group}
     */
    private function lockGroupsForMove(Group $sourceGroup, Group $targetGroup): array
    {
        $ordered = [$sourceGroup, $targetGroup];
        usort($ordered, fn (Group $left, Group $right): int => $left->id <=> $right->id);

        $lockedById = [];

        foreach ($ordered as $group) {
            $locked = Group::query()
                ->whereKey($group->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->setRelation('competition', $group->competition);
            $locked->setRelation(
                'groupEntries',
                $locked->groupEntries()->lockForUpdate()->get(),
            );
            $locked->games()->lockForUpdate()->get();
            $lockedById[$locked->id] = $locked;
        }

        return [
            $lockedById[$sourceGroup->id],
            $lockedById[$targetGroup->id],
        ];
    }
}
