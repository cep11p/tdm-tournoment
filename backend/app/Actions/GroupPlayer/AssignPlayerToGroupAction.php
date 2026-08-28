<?php

namespace App\Actions\GroupPlayer;

use App\Actions\Group\PersistGroupEntryAction;
use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\GroupPlayerStatus;
use App\Models\Group;
use App\Models\GroupEntry;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Competition\CompetitionStructureGuard;
use App\Support\Competition\ResolveCompetitionEntryForGroup;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;

final class AssignPlayerToGroupAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ResolveCompetitionEntryForGroup $resolveCompetitionEntryForGroup,
        private readonly PersistGroupEntryAction $persistGroupEntry,
    ) {}

    /**
     * @param  array{group_id: int, player_id?: int, competition_entry_id?: int}  $payload
     */
    public function __invoke(array $payload): GroupEntry
    {
        $group = Group::query()->findOrFail($payload['group_id']);
        $group->loadMissing('competition.tournament');
        TournamentLifecycleGuard::ensureMutableForGroup($group);
        CompetitionFormatGuard::ensureGroupStage($group->competition);
        CompetitionStructureGuard::ensureEditable($group->competition);

        $entry = ($this->resolveCompetitionEntryForGroup)($group->competition, $payload);

        return DB::transaction(function () use ($group, $entry): GroupEntry {
            $groupEntry = ($this->persistGroupEntry)($group, $entry);
            $groupEntry->load([
                'competitionEntry.members.player:id,first_name,last_name,nickname',
                'competitionEntry.competition',
            ]);

            $status = $groupEntry->status ?? GroupPlayerStatus::Active;
            $entryContext = AuditContextBuilder::fromGroupEntry($groupEntry);

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GROUP_PLAYER_ASSIGNED,
                logName: 'groups',
                subject: $group,
                context: array_merge(
                    AuditContextBuilder::fromGroup($group),
                    $entryContext,
                ),
                new: [
                    'group_id' => $group->id,
                    'competition_entry_id' => $entry->id,
                    'status' => $status instanceof GroupPlayerStatus ? $status->value : (string) $status,
                    ...$entryContext,
                ],
                summary: [
                    'group_id' => $group->id,
                    'group_name' => $group->name,
                    ...$entryContext,
                ],
            ));

            return $groupEntry;
        });
    }
}
