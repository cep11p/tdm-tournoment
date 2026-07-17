<?php

namespace App\Actions\Tournament;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Support\Audit\AuditChangeResolver;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

final class CreateTournamentAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(array $payload): Tournament
    {
        $payload['status'] ??= TournamentStatus::Draft;

        return DB::transaction(function () use ($payload): Tournament {
            $tournament = Tournament::query()->create($payload);

            $new = AuditChangeResolver::normalizeAttributes([
                'name' => $tournament->name,
                'location' => $tournament->location,
                'start_date' => $tournament->start_date,
                'end_date' => $tournament->end_date,
                'status' => $tournament->status,
            ]);

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::TOURNAMENT_CREATED,
                logName: 'tournaments',
                subject: $tournament,
                context: AuditContextBuilder::fromTournament($tournament),
                new: $new,
                summary: [
                    'tournament_id' => $tournament->id,
                    'tournament_name' => $tournament->name,
                ],
            ));

            return $tournament;
        });
    }
}
