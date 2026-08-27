<?php

namespace App\Actions\Registration;

use App\Actions\CompetitionEntry\PersistCompetitionEntryAction;
use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Player;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

final class RegisterPlayerToCompetitionAction
{
    public function __construct(
        private readonly PersistCompetitionEntryAction $persistCompetitionEntry,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(array $payload): CompetitionEntry
    {
        return DB::transaction(function () use ($payload): CompetitionEntry {
            $entry = ($this->persistCompetitionEntry)($payload);

            $competition = Competition::query()->findOrFail($entry->competition_id);
            $playerId = (int) $payload['player_id'];
            $player = Player::query()->findOrFail($playerId);

            $context = AuditContextBuilder::fromRegistrationContext(
                $competition,
                $player,
                $entry,
            );

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::REGISTRATION_CREATED,
                logName: 'registrations',
                subject: $competition,
                context: $context,
                new: [
                    'competition_id' => $entry->competition_id,
                    'player_id' => $playerId,
                    'competition_entry_id' => $entry->id,
                ],
                summary: [
                    'registration_id' => $entry->id,
                    'competition_entry_id' => $entry->id,
                    'player_id' => $player->id,
                    'player_name' => $context['player_name'],
                ],
            ));

            return $entry;
        });
    }
}
