<?php

namespace App\Actions\CheckIn;

use App\Enums\CompetitionCheckInBulkAction;
use App\Enums\CompetitionEntryStatus;
use App\Models\Competition;
use App\Models\CompetitionEntryMember;
use App\Support\Competition\BuildCompetitionCheckInPayload;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;

final class BulkUpdateCompetitionCheckInAction
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Competition $competition, CompetitionCheckInBulkAction $action): array
    {
        $competition->loadMissing('tournament');
        TournamentLifecycleGuard::ensureMutableForCompetition($competition);

        DB::transaction(function () use ($competition, $action): void {
            $query = CompetitionEntryMember::query()
                ->where('competition_id', $competition->id)
                ->whereHas(
                    'competitionEntry',
                    fn ($entryQuery) => $entryQuery->where('status', CompetitionEntryStatus::Active->value),
                );

            if ($action === CompetitionCheckInBulkAction::MarkAllPresent) {
                $query->whereNull('checked_in_at')->update([
                    'checked_in_at' => now(),
                ]);

                return;
            }

            $query->whereNotNull('checked_in_at')->update([
                'checked_in_at' => null,
            ]);
        });

        return BuildCompetitionCheckInPayload::for($competition->fresh());
    }
}
