<?php

namespace App\Actions\Group;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Enums\GroupPlayerStatus;
use App\Enums\GroupPlayerStatusReason;
use App\Models\Group;
use App\Models\GroupEntry;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Competition\ResolveCompetitionEntryForGroup;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetGroupEntryStatusAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ResolveCompetitionEntryForGroup $resolveCompetitionEntryForGroup,
    ) {}

    /**
     * @param  array{
     *     player_id?: int,
     *     competition_entry_id?: int,
     *     status: GroupPlayerStatus,
     *     reason?: ?GroupPlayerStatusReason,
     *     notes?: ?string
     * }  $payload
     */
    public function __invoke(Group $group, array $payload): GroupEntry
    {
        $group->loadMissing('competition.tournament');
        TournamentLifecycleGuard::ensureMutableForGroup($group);

        CompetitionFormatGuard::ensureGroupStage($group->competition);

        if ($group->competition->brackets()->exists()) {
            throw ValidationException::withMessages([
                'group' => ['No se puede cambiar el estado del participante cuando ya existe un cuadro eliminatorio.'],
            ]);
        }

        $entry = ($this->resolveCompetitionEntryForGroup)($group->competition, $payload);

        $groupEntry = GroupEntry::query()
            ->where('group_id', $group->id)
            ->where('competition_entry_id', $entry->id)
            ->first();

        if ($groupEntry === null) {
            throw ValidationException::withMessages([
                'competition_entry_id' => ['La participación no pertenece al grupo.'],
            ]);
        }

        if (! $groupEntry->isActive()) {
            throw ValidationException::withMessages([
                'competition_entry_id' => ['La participación ya no está activa en el grupo.'],
            ]);
        }

        $newStatus = $payload['status'];

        if ($newStatus === GroupPlayerStatus::Active) {
            throw ValidationException::withMessages([
                'status' => ['No se permite reactivar participantes en esta versión.'],
            ]);
        }

        return DB::transaction(function () use (
            $group,
            $groupEntry,
            $payload,
            $newStatus,
        ): GroupEntry {
            $oldStatus = $groupEntry->status;
            $statusPayload = [
                'status' => $newStatus,
                'status_reason' => $payload['reason'] ?? null,
                'status_notes' => $payload['notes'] ?? null,
                'status_changed_at' => now(),
            ];

            $groupEntry->update($statusPayload);

            $gamesClosed = $this->closePendingGroupGamesForEntry($group, (int) $groupEntry->competition_entry_id);

            $groupEntry = $groupEntry->fresh([
                'competitionEntry.members.player:id,first_name,last_name,nickname',
                'competitionEntry.competition',
            ]);

            $entryContext = AuditContextBuilder::fromGroupEntry($groupEntry);

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GROUP_PLAYER_STATUS_CHANGED,
                logName: 'groups',
                subject: $group,
                context: array_merge(
                    AuditContextBuilder::fromGroup($group),
                    $entryContext,
                ),
                old: [
                    'status' => $oldStatus->value,
                ],
                new: [
                    'status' => $newStatus->value,
                    'reason_code' => ($payload['reason'] ?? null) instanceof GroupPlayerStatusReason
                        ? $payload['reason']->value
                        : null,
                    ...$entryContext,
                ],
                summary: [
                    ...$entryContext,
                    'games_closed' => $gamesClosed,
                    'games_affected' => $gamesClosed,
                ],
                reason: $payload['notes'] ?? null,
            ));

            return $groupEntry;
        });
    }

    private function closePendingGroupGamesForEntry(Group $group, int $entryId): int
    {
        $openGames = $group->games()
            ->whereIn('status', [GameStatus::Pending, GameStatus::InProgress])
            ->where(function ($query) use ($entryId): void {
                $query->where('entry1_id', $entryId)
                    ->orWhere('entry2_id', $entryId);
            })
            ->get();

        foreach ($openGames as $game) {
            $opponentEntryId = (int) $game->entry1_id === $entryId
                ? (int) $game->entry2_id
                : (int) $game->entry1_id;

            if ($opponentEntryId <= 0) {
                continue;
            }

            $game->update([
                'status' => GameStatus::Finished,
                'winner_entry_id' => $opponentEntryId,
                'finished_at' => now(),
            ]);
        }

        return $openGames->count();
    }
}
