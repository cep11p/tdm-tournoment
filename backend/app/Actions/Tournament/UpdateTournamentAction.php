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
use Illuminate\Validation\ValidationException;

final class UpdateTournamentAction
{
    /**
     * @var list<string>
     */
    private const AUDITABLE_FIELDS = [
        'name',
        'location',
        'start_date',
        'end_date',
        'status',
    ];

    /**
     * @var list<string>
     */
    private const DESCRIPTIVE_FIELDS = [
        'name',
        'location',
        'start_date',
        'end_date',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(Tournament $tournament, array $payload): Tournament
    {
        return DB::transaction(function () use ($tournament, $payload): Tournament {
            $tournament = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

            if ($tournament->status === TournamentStatus::Finished) {
                if (array_key_exists('status', $payload)) {
                    throw ValidationException::withMessages([
                        'status' => ['No se puede modificar el estado de un torneo finalizado.'],
                    ]);
                }

                $payload = array_intersect_key($payload, array_flip(self::DESCRIPTIVE_FIELDS));
            }

            if (isset($payload['status']) && $payload['status'] === TournamentStatus::Finished->value) {
                throw ValidationException::withMessages([
                    'status' => ['El torneo solo puede finalizarse mediante la operación de cierre.'],
                ]);
            }

            $tournament->fill($payload);

            $changes = AuditChangeResolver::resolve($tournament, self::AUDITABLE_FIELDS);

            if ($changes === null) {
                return $tournament;
            }

            $tournament->save();

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::TOURNAMENT_UPDATED,
                logName: 'tournaments',
                subject: $tournament->refresh(),
                context: AuditContextBuilder::fromTournament($tournament),
                old: $changes['old'],
                new: $changes['new'],
                summary: [
                    'tournament_id' => $tournament->id,
                    'tournament_name' => $tournament->name,
                    'changed_fields' => array_keys($changes['new']),
                ],
            ));

            return $tournament;
        });
    }
}
