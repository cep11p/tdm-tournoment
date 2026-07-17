<?php

namespace App\Actions\Competition;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Competition;
use App\Support\Audit\AuditCompetitionAttributes;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionCategorySync;
use Illuminate\Support\Facades\DB;

final class CreateCompetitionAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(array $payload): Competition
    {
        unset($payload['sets_to_win']);

        $payload = CompetitionCategorySync::apply($payload);

        $groupStageBestOf = (int) ($payload['group_stage_best_of'] ?? 5);
        $payload['sets_to_win'] = intdiv($groupStageBestOf, 2) + 1;

        return DB::transaction(function () use ($payload): Competition {
            $competition = Competition::query()->create($payload);
            $competition->loadMissing(['tournament', 'categoryModel:id,name']);

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::COMPETITION_CREATED,
                logName: 'competitions',
                subject: $competition,
                context: AuditContextBuilder::fromCompetition($competition),
                new: AuditCompetitionAttributes::snapshot($competition),
                summary: [
                    'competition_id' => $competition->id,
                    'competition_name' => $competition->name,
                    'tournament_id' => $competition->tournament_id,
                    'tournament_name' => $competition->tournament?->name,
                ],
            ));

            return $competition;
        });
    }
}
