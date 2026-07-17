<?php

namespace App\Actions\Group;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Competition;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Competition\CompetitionStructureGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerateRandomGroupsForCompetitionAction
{
    public function __construct(
        private readonly BuildRandomGroupsForCompetitionAction $buildRandomGroups,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{
     *     groups_created: int,
     *     players_assigned: int,
     *     games_created: int,
     *     groups: \Illuminate\Support\Collection<int, \App\Models\Group>,
     * }
     */
    public function __invoke(Competition $competition, int $groupsCount): array
    {
        CompetitionFormatGuard::ensureGroupStage($competition);
        CompetitionStructureGuard::ensureEditable($competition);

        if ($competition->groups()->exists()) {
            throw ValidationException::withMessages([
                'competition' => ['La competencia ya tiene grupos configurados.'],
            ]);
        }

        return DB::transaction(function () use ($competition, $groupsCount): array {
            $result = ($this->buildRandomGroups)($competition, $groupsCount);

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GROUPS_GENERATED,
                logName: 'groups',
                subject: $competition,
                context: AuditContextBuilder::fromCompetition($competition),
                new: [
                    'groups_count' => $result['groups_created'],
                    'games_count' => $result['games_created'],
                ],
                summary: [
                    'requested_groups_count' => $groupsCount,
                    'groups_created' => $result['groups_created'],
                    'players_assigned' => $result['players_assigned'],
                    'games_created' => $result['games_created'],
                ],
            ));

            return $result;
        });
    }
}
