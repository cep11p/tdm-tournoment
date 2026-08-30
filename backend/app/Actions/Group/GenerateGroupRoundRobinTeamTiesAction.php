<?php

namespace App\Actions\Group;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\CompetitionType;
use App\Models\Group;
use App\Models\TeamTie;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Competition\TeamCompetitionSchedulingGuard;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerateGroupRoundRobinTeamTiesAction
{
    public function __construct(
        private readonly BuildGroupRoundRobinTeamTiesAction $buildRoundRobin,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return Collection<int, TeamTie>
     */
    public function __invoke(Group $group): Collection
    {
        $group->loadMissing('competition.tournament');
        TournamentLifecycleGuard::ensureMutableForGroup($group);
        CompetitionFormatGuard::ensureGroupStage($group->competition);
        TeamCompetitionSchedulingGuard::ensureFormatConfigured($group->competition);

        $type = $group->competition->type instanceof CompetitionType
            ? $group->competition->type
            : CompetitionType::from((string) $group->competition->type);

        if ($type !== CompetitionType::Team) {
            throw ValidationException::withMessages([
                'group' => ['La generación de enfrentamientos por equipos solo aplica a competencias por equipos.'],
            ]);
        }

        $entryCount = $group->groupEntries()->count();

        if ($entryCount < 2) {
            throw ValidationException::withMessages([
                'group' => ['El grupo necesita al menos 2 equipos.'],
            ]);
        }

        if ($group->teamTies()->exists()) {
            throw ValidationException::withMessages([
                'group' => ['Los enfrentamientos del round robin ya fueron generados para este grupo.'],
            ]);
        }

        return DB::transaction(function () use ($group, $entryCount): Collection {
            $created = ($this->buildRoundRobin)($group);
            $teamTiesCreated = $created->count();

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GROUPS_ROUND_ROBIN_GENERATED,
                logName: 'groups',
                subject: $group,
                context: AuditContextBuilder::fromGroup($group),
                new: [
                    'team_ties_count' => $teamTiesCreated,
                ],
                summary: [
                    'schedule_type' => 'team_tie',
                    'teams_assigned' => $entryCount,
                    'team_ties_created' => $teamTiesCreated,
                    'existing_team_ties_before' => 0,
                ],
            ));

            return $created;
        });
    }
}
