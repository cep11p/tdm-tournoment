<?php

namespace App\Actions\Group;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Group;
use App\Models\Competition;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Competition\CompetitionStructureGuard;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateGroupAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(array $payload): Group
    {
        $competition = Competition::query()->findOrFail($payload['competition_id']);
        TournamentLifecycleGuard::ensureMutableForCompetition($competition);
        CompetitionFormatGuard::ensureGroupStage($competition);
        CompetitionStructureGuard::ensureEditable($competition);

        return DB::transaction(function () use ($payload): Group {
            try {
                $group = Group::query()->create($payload);
            } catch (QueryException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    throw ValidationException::withMessages([
                        'name' => ['Ya existe un grupo con ese nombre en esta competencia.'],
                    ]);
                }

                throw $exception;
            }

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::GROUP_CREATED,
                logName: 'groups',
                subject: $group,
                context: AuditContextBuilder::fromGroup($group),
                new: [
                    'name' => $group->name,
                ],
                summary: [
                    'group_id' => $group->id,
                    'group_name' => $group->name,
                ],
            ));

            return $group;
        });
    }
}
