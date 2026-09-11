<?php

namespace App\Actions\Group;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Game;
use App\Models\Group;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Competition\LateGroupMutationGuard;
use App\Support\Competition\TeamCompetitionSchedulingGuard;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerateGroupRoundRobinGamesAction
{
    public function __construct(
        private readonly BuildGroupRoundRobinGamesAction $buildRoundRobin,
        private readonly AuditLogger $auditLogger,
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

        return DB::transaction(fn (): Collection => $this->syncLocked($group));
    }

    /**
     * Completa el round-robin del grupo. Debe invocarse dentro de una transacción
     * ya abierta. Toma locks en orden: Group → GroupEntries → Games.
     *
     * @return Collection<int, Game>
     */
    public function syncLocked(Group $group): Collection
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

        $lockedGroup->groupEntries()->lockForUpdate()->get();
        $existingBefore = $lockedGroup->games()->lockForUpdate()->count();
        $entryCount = $lockedGroup->groupEntries()->count();

        if ($entryCount < 2) {
            throw ValidationException::withMessages([
                'group' => ['El grupo necesita al menos 2 jugadores.'],
            ]);
        }

        $created = ($this->buildRoundRobin)($lockedGroup);
        $gamesCreated = $created->count();

        if ($gamesCreated === 0) {
            return $created;
        }

        $this->auditLogger->log(new AuditEntry(
            action: AuditAction::GROUPS_ROUND_ROBIN_GENERATED,
            logName: 'groups',
            subject: $lockedGroup,
            context: AuditContextBuilder::fromGroup($lockedGroup),
            new: [
                'games_count' => $gamesCreated,
                'games_total_after' => $existingBefore + $gamesCreated,
            ],
            summary: [
                'player_count' => $entryCount,
                'games_created' => $gamesCreated,
                'existing_games_before' => $existingBefore,
                'games_total_after' => $existingBefore + $gamesCreated,
            ],
        ));

        return $created;
    }
}
