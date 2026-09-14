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

final class RemoveEntryFromGroupAction
{
    public const NOT_IN_GROUP_MESSAGE = 'El participante no pertenece a este grupo.';

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ReconcileGroupRoundRobinGamesAction $reconcileRoundRobin,
    ) {}

    public function __invoke(Group $group, int $competitionEntryId): void
    {
        $group->loadMissing('competition.tournament');
        TournamentLifecycleGuard::ensureMutableForGroup($group);
        CompetitionFormatGuard::ensureGroupStage($group->competition);
        TeamCompetitionSchedulingGuard::ensureGamesRoundRobinAllowed($group->competition);
        LateGroupMutationGuard::ensureAllowed($group->competition);

        DB::transaction(function () use ($group, $competitionEntryId): void {
            $lockedGroup = Group::query()
                ->whereKey($group->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedGroup->setRelation('competition', $group->competition);

            $lockedEntries = $lockedGroup->groupEntries()->lockForUpdate()->get();
            $lockedGroup->games()->lockForUpdate()->get();

            $groupEntry = $lockedEntries->first(
                fn (GroupEntry $entry): bool => (int) $entry->competition_entry_id === $competitionEntryId,
            );

            if (! $groupEntry instanceof GroupEntry) {
                throw ValidationException::withMessages([
                    'competition_entry_id' => [self::NOT_IN_GROUP_MESSAGE],
                ]);
            }

            $groupEntry->load([
                'competitionEntry.members.player:id,first_name,last_name,nickname',
                'competitionEntry.competition',
            ]);
            $entryContext = AuditContextBuilder::fromGroupEntry($groupEntry);

            $groupEntry->delete();

            ($this->reconcileRoundRobin)->reconcileLocked($lockedGroup);

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GROUP_PLAYER_REMOVED,
                logName: 'groups',
                subject: $lockedGroup,
                context: array_merge(
                    AuditContextBuilder::fromGroup($lockedGroup),
                    $entryContext,
                ),
                old: [
                    'group_id' => $lockedGroup->id,
                    'competition_entry_id' => $competitionEntryId,
                    ...$entryContext,
                ],
                summary: [
                    'group_id' => $lockedGroup->id,
                    'group_name' => $lockedGroup->name,
                    ...$entryContext,
                ],
            ));
        });
    }
}
