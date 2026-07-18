<?php

namespace App\Actions\Group;

use App\Enums\ManualTiebreakReason;
use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Group;
use App\Models\GroupManualTiebreak;
use App\Models\GroupManualTiebreakPlayer;
use App\Models\Player;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Group\GroupStandingsCalculator;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApplyGroupManualTiebreakAction
{
    public function __construct(
        private readonly GroupStandingsCalculator $groupStandingsCalculator,
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

        $groupPlayerIds = $group->groupPlayers()
            ->pluck('player_id')
            ->map(fn (int $playerId): int => (int) $playerId)
            ->all();

        $invalidPlayers = array_diff($playerIds, $groupPlayerIds);

        if ($invalidPlayers !== []) {
            throw ValidationException::withMessages([
                'player_ids' => ['Uno o más jugadores no pertenecen al grupo.'],
            ]);
        }

        $automaticResult = $this->groupStandingsCalculator->calculateAutomaticOnly($group);
        $pendingGroups = $automaticResult->manualTiebreakGroups;

        if ($pendingGroups === []) {
            throw ValidationException::withMessages([
                'player_ids' => ['No hay empates pendientes de definición manual en este grupo.'],
            ]);
        }

        if (! $this->matchesPendingGroup($playerIds, $pendingGroups)) {
            throw ValidationException::withMessages([
                'player_ids' => ['Los jugadores enviados no coinciden con un empate pendiente actual.'],
            ]);
        }

        $existingTiebreak = $this->findExistingTiebreak($group, $playerIds);
        $oldOrderedPlayerIds = $existingTiebreak?->orderedPlayerIds() ?? [];

        return DB::transaction(function () use ($group, $playerIds, $payload, $oldOrderedPlayerIds): GroupManualTiebreak {
            $existingTiebreak = $this->findExistingTiebreak($group, $playerIds);

            if ($existingTiebreak instanceof GroupManualTiebreak) {
                $existingTiebreak->update([
                    'reason' => $payload['reason'],
                    'notes' => $payload['notes'] ?? null,
                    'applied_at' => now(),
                ]);

                $existingTiebreak->players()->delete();
                $tiebreak = $existingTiebreak;
            } else {
                $tiebreak = GroupManualTiebreak::query()->create([
                    'group_id' => $group->id,
                    'reason' => $payload['reason'],
                    'notes' => $payload['notes'] ?? null,
                    'applied_at' => now(),
                ]);
            }

            foreach ($playerIds as $index => $playerId) {
                GroupManualTiebreakPlayer::query()->create([
                    'group_manual_tiebreak_id' => $tiebreak->id,
                    'player_id' => $playerId,
                    'position' => $index + 1,
                ]);
            }

            $tiebreak->load(['players.player:id,first_name,last_name']);

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
                        ->map(function (int $playerId) use ($tiebreak): array {
                            $entry = $tiebreak->players->firstWhere('player_id', $playerId);
                            $player = $entry?->player;

                            return [
                                'id' => $playerId,
                                'name' => $player instanceof Player
                                    ? trim(sprintf('%s %s', $player->first_name, $player->last_name))
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
     * @param  array<int, int>  $playerIds
     * @param  array<int, array{player_ids: array<int, int>, player_names: array<int, string>}>  $pendingGroups
     */
    private function matchesPendingGroup(array $playerIds, array $pendingGroups): bool
    {
        foreach ($pendingGroups as $pendingGroup) {
            $pendingIds = array_map('intval', $pendingGroup['player_ids'] ?? []);

            if ($this->playerSetsMatch($playerIds, $pendingIds)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, int>  $left
     * @param  array<int, int>  $right
     */
    private function playerSetsMatch(array $left, array $right): bool
    {
        $leftSorted = array_map('intval', $left);
        $rightSorted = array_map('intval', $right);
        sort($leftSorted);
        sort($rightSorted);

        return $leftSorted === $rightSorted;
    }

    /**
     * @param  array<int, int>  $playerIds
     */
    private function findExistingTiebreak(Group $group, array $playerIds): ?GroupManualTiebreak
    {
        $tiebreaks = $group->manualTiebreaks()
            ->with('players')
            ->get();

        foreach ($tiebreaks as $tiebreak) {
            if ($this->playerSetsMatch($tiebreak->orderedPlayerIds(), $playerIds)) {
                return $tiebreak;
            }
        }

        return null;
    }
}
