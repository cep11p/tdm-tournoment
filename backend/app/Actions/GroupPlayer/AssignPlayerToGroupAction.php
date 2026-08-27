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
use App\Support\Competition\ResolveSinglesEntryForPlayer;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;

final class AssignPlayerToGroupAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ResolveSinglesEntryForPlayer $resolveSinglesEntryForPlayer,
        private readonly PersistGroupEntryAction $persistGroupEntry,
    ) {}

    public function __invoke(array $payload): GroupEntry
    {
        $group = Group::query()->findOrFail($payload['group_id']);
        $group->loadMissing('competition.tournament');
        TournamentLifecycleGuard::ensureMutableForGroup($group);
        CompetitionFormatGuard::ensureGroupStage($group->competition);
        CompetitionStructureGuard::ensureEditable($group->competition);
        $playerId = (int) $payload['player_id'];

        $entry = ($this->resolveSinglesEntryForPlayer)($group->competition, $playerId);

        return DB::transaction(function () use ($group, $entry, $playerId): GroupEntry {
            $groupEntry = ($this->persistGroupEntry)($group, $entry);
            $groupEntry->load([
                'competitionEntry.members.player:id,first_name,last_name,nickname',
            ]);

            $status = $groupEntry->status ?? GroupPlayerStatus::Active;
            $player = $groupEntry->competitionEntry?->singlesPlayer();
            $playerName = $player !== null
                ? trim(sprintf('%s %s', $player->first_name, $player->last_name))
                : null;

            $context = array_merge(
                AuditContextBuilder::fromGroup($group),
                [
                    'player_id' => $playerId,
                    'player_name' => $playerName !== '' ? $playerName : null,
                ],
            );

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GROUP_PLAYER_ASSIGNED,
                logName: 'groups',
                subject: $group,
                context: $context,
                new: [
                    'group_id' => $group->id,
                    'player_id' => $playerId,
                    'status' => $status instanceof GroupPlayerStatus ? $status->value : (string) $status,
                ],
                summary: [
                    'group_id' => $group->id,
                    'group_name' => $group->name,
                    'player_id' => $playerId,
                    'player_name' => $playerName !== '' ? $playerName : null,
                ],
            ));

            return $groupEntry;
        });
    }
}
