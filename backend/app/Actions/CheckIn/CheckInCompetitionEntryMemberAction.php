<?php

namespace App\Actions\CheckIn;

use App\Enums\CompetitionEntryStatus;
use App\Models\Competition;
use App\Models\CompetitionEntryMember;
use App\Support\Competition\BuildCompetitionCheckInPayload;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Validation\ValidationException;

final class CheckInCompetitionEntryMemberAction
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Competition $competition, CompetitionEntryMember $member): array
    {
        $competition->loadMissing('tournament');
        TournamentLifecycleGuard::ensureMutableForCompetition($competition);

        $member->loadMissing('competitionEntry');
        $this->ensureEntryIsActive($member);

        if (! $member->isCheckedIn()) {
            $member->update([
                'checked_in_at' => now(),
            ]);
            $member->refresh();
        }

        $member->loadMissing('player:id,first_name,last_name,nickname');

        return BuildCompetitionCheckInPayload::memberPayload($member);
    }

    private function ensureEntryIsActive(CompetitionEntryMember $member): void
    {
        $status = $member->competitionEntry?->status;

        $normalized = $status instanceof CompetitionEntryStatus
            ? $status
            : ($status !== null ? CompetitionEntryStatus::from((string) $status) : null);

        if ($normalized === CompetitionEntryStatus::Active) {
            return;
        }

        throw ValidationException::withMessages([
            'member' => ['No se puede hacer check-in de una inscripción que no está activa.'],
        ]);
    }
}
