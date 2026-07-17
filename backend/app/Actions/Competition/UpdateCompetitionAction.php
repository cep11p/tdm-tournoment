<?php

namespace App\Actions\Competition;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Competition;
use App\Support\Audit\AuditChangeResolver;
use App\Support\Audit\AuditCompetitionAttributes;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionCategorySync;
use Illuminate\Support\Facades\DB;

final class UpdateCompetitionAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(Competition $competition, array $payload): Competition
    {
        unset($payload['sets_to_win']);

        $payload = CompetitionCategorySync::apply($payload, $competition);

        return DB::transaction(function () use ($competition, $payload): Competition {
            $competition->fill($payload);

            $changes = AuditChangeResolver::resolve(
                $competition,
                AuditCompetitionAttributes::auditableFields(),
            );

            if ($changes === null) {
                return $competition;
            }

            $changes = AuditCompetitionAttributes::enrichCategoryNames($changes);

            $competition->save();
            $competition->loadMissing(['tournament', 'categoryModel:id,name']);

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::COMPETITION_UPDATED,
                logName: 'competitions',
                subject: $competition,
                context: AuditContextBuilder::fromCompetition($competition),
                old: $changes['old'],
                new: $changes['new'],
                summary: [
                    'competition_id' => $competition->id,
                    'competition_name' => $competition->name,
                    'changed_fields' => array_keys($changes['new']),
                ],
            ));

            return $competition->refresh();
        });
    }
}
