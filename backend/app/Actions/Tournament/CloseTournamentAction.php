<?php

namespace App\Actions\Tournament;

use App\Actions\Competition\ConsolidateCompetitionOutcomeAction;
use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Competition\CompetitionStatusResolver;
use App\Support\Tournament\TournamentClosureGuard;
use Illuminate\Support\Facades\DB;

final class CloseTournamentAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ConsolidateCompetitionOutcomeAction $consolidateOutcome,
    ) {}

    public function __invoke(Tournament $tournament): Tournament
    {
        return DB::transaction(function () use ($tournament): Tournament {
            $tournament = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

            $closureSummary = TournamentClosureGuard::ensureCanClose($tournament);

            foreach ($tournament->competitions()->orderBy('id')->get() as $competition) {
                if (CompetitionStatusResolver::resolve($competition)['code'] === 'completed') {
                    ($this->consolidateOutcome)($competition);
                }
            }

            $oldStatus = $tournament->status instanceof TournamentStatus
                ? $tournament->status->value
                : (string) $tournament->status;

            $tournament->status = TournamentStatus::Finished;
            $tournament->closed_at = now();
            $tournament->save();

            $tournament->refresh();

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::TOURNAMENT_CLOSED,
                logName: 'tournaments',
                subject: $tournament,
                context: AuditContextBuilder::fromTournament($tournament),
                old: [
                    'status' => $oldStatus,
                    'closed_at' => null,
                ],
                new: [
                    'status' => TournamentStatus::Finished->value,
                    'closed_at' => $tournament->closed_at?->toIso8601String(),
                ],
                summary: [
                    'tournament_id' => $tournament->id,
                    'tournament_name' => $tournament->name,
                    'competitions_count' => $closureSummary['competitions_count'],
                    'completed_competitions' => $closureSummary['completed_competitions'],
                    'unused_competitions' => $closureSummary['unused_competitions'],
                    'games_count' => $closureSummary['games_count'],
                    'results' => $closureSummary['results'],
                ],
            ));

            return $tournament;
        });
    }
}
