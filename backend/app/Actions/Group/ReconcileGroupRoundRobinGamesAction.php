<?php

namespace App\Actions\Group;

use App\Actions\Game\DeleteGameAction;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\Group;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Competition\LateGroupMutationGuard;
use App\Support\Competition\TeamCompetitionSchedulingGuard;
use App\Support\Group\GroupRoundRobinSlotAllocator;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReconcileGroupRoundRobinGamesAction
{
    public const BLOCKED_MESSAGE = 'El grupo no puede modificarse porque existen partidos iniciados o finalizados de los integrantes afectados.';

    public function __construct(
        private readonly BuildGroupRoundRobinGamesAction $buildRoundRobin,
        private readonly DeleteGameAction $deleteGame,
    ) {}

    /**
     * @return Collection<int, Game>
     */
    public function __invoke(Group $group): Collection
    {
        $group->loadMissing('competition.tournament');
        TournamentLifecycleGuard::ensureMutableForGroup($group);
        CompetitionFormatGuard::ensureGroupStage($group->competition);
        TeamCompetitionSchedulingGuard::ensureGamesRoundRobinAllowed($group->competition);
        LateGroupMutationGuard::ensureAllowed($group->competition);

        return DB::transaction(fn (): Collection => $this->reconcileLocked($group));
    }

    /**
     * Reconcilia el fixture round-robin con la composición actual del grupo.
     * Debe invocarse dentro de una transacción ya abierta.
     * Toma locks en orden: Group → GroupEntries → Games.
     *
     * @return Collection<int, Game>
     */
    public function reconcileLocked(Group $group): Collection
    {
        $lockedGroup = Group::query()
            ->whereKey($group->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($group->relationLoaded('competition')) {
            $lockedGroup->setRelation('competition', $group->competition);
        } else {
            $lockedGroup->loadMissing('competition');
        }

        TeamCompetitionSchedulingGuard::ensureGamesRoundRobinAllowed($lockedGroup->competition);

        $entryIds = $lockedGroup->groupEntries()
            ->lockForUpdate()
            ->orderBy('competition_entry_id')
            ->pluck('competition_entry_id')
            ->map(fn ($entryId): int => (int) $entryId)
            ->values()
            ->all();

        $games = $lockedGroup->games()
            ->with('sets')
            ->lockForUpdate()
            ->get();

        $expectedPairs = $this->expectedPairKeys($entryIds);
        $obsoleteSafe = [];

        foreach ($games as $game) {
            if ($this->isExpectedGame($game, $expectedPairs)) {
                continue;
            }

            if (! $this->isSafeToDelete($game)) {
                throw ValidationException::withMessages([
                    'group' => [self::BLOCKED_MESSAGE],
                ]);
            }

            $obsoleteSafe[] = $game;
        }

        foreach ($obsoleteSafe as $game) {
            ($this->deleteGame)($game);
        }

        ($this->buildRoundRobin)($lockedGroup);

        return $lockedGroup->games()->orderBy('id')->get();
    }

    /**
     * @param  list<int>  $entryIds
     * @return array<string, true>
     */
    private function expectedPairKeys(array $entryIds): array
    {
        $keys = [];
        $count = count($entryIds);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $keys[GroupRoundRobinSlotAllocator::pairKey($entryIds[$i], $entryIds[$j])] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, true>  $expectedPairs
     */
    private function isExpectedGame(Game $game, array $expectedPairs): bool
    {
        if ($game->entry1_id === null || $game->entry2_id === null) {
            return false;
        }

        $pairKey = GroupRoundRobinSlotAllocator::pairKey(
            (int) $game->entry1_id,
            (int) $game->entry2_id,
        );

        return isset($expectedPairs[$pairKey]);
    }

    private function isSafeToDelete(Game $game): bool
    {
        if ($game->status !== GameStatus::Pending) {
            return false;
        }

        if ($game->winner_entry_id !== null) {
            return false;
        }

        if ($game->finished_at !== null) {
            return false;
        }

        if ($game->is_bye) {
            return false;
        }

        if ($game->sets->isNotEmpty()) {
            return false;
        }

        return true;
    }
}
