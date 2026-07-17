<?php

namespace App\Actions\Registration;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Competition;
use App\Models\Player;
use App\Models\Registration;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

final class RegisterPlayerToCompetitionAction
{
    public function __construct(
        private readonly PersistRegistrationAction $persistRegistration,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(array $payload): Registration
    {
        return DB::transaction(function () use ($payload): Registration {
            $registration = ($this->persistRegistration)($payload);

            $competition = Competition::query()->findOrFail($registration->competition_id);
            $player = Player::query()->findOrFail($registration->player_id);

            $context = AuditContextBuilder::fromRegistrationContext(
                $competition,
                $player,
                $registration,
            );

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::REGISTRATION_CREATED,
                logName: 'registrations',
                subject: $competition,
                context: $context,
                new: [
                    'competition_id' => $registration->competition_id,
                    'player_id' => $registration->player_id,
                ],
                summary: [
                    'registration_id' => $registration->id,
                    'player_id' => $player->id,
                    'player_name' => $context['player_name'],
                ],
            ));

            return $registration;
        });
    }
}
