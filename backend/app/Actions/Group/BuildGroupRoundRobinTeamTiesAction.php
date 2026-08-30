<?php

namespace App\Actions\Group;

use App\Enums\TeamTieStatus;
use App\Models\Group;
use App\Models\TeamTie;
use App\Models\TeamTieFormat;
use App\Support\Competition\TeamCompetitionSchedulingGuard;
use App\Support\Group\RoundRobinScheduleBuilder;
use Illuminate\Support\Collection;

final class BuildGroupRoundRobinTeamTiesAction
{
    public function __construct(
        private readonly RoundRobinScheduleBuilder $scheduleBuilder,
    ) {}

    /**
     * @return Collection<int, TeamTie>
     */
    public function __invoke(Group $group): Collection
    {
        $group->loadMissing(['competition.teamTieFormat.slots']);

        TeamCompetitionSchedulingGuard::ensureFormatConfigured($group->competition);

        /** @var TeamTieFormat $format */
        $format = $group->competition->teamTieFormat;

        $entryIds = $group->groupEntries()
            ->orderBy('competition_entry_id')
            ->pluck('competition_entry_id')
            ->map(fn ($entryId) => (int) $entryId)
            ->values()
            ->all();

        $competitionId = (int) $group->competition_id;
        $schedule = $this->scheduleBuilder->build($entryIds);
        $created = collect();

        foreach ($schedule as $roundIndex => $roundPairings) {
            $groupRound = $roundIndex + 1;

            foreach ($roundPairings as $matchIndex => $pairing) {
                $entry1Id = $pairing['entry1_id'];
                $entry2Id = $pairing['entry2_id'];

                if ($this->teamTieExistsBetweenEntries($group->id, $entry1Id, $entry2Id)) {
                    continue;
                }

                $created->push(TeamTie::query()->create([
                    'competition_id' => $competitionId,
                    'group_id' => $group->id,
                    'entry1_id' => $entry1Id,
                    'entry2_id' => $entry2Id,
                    'team_tie_format_id' => $format->id,
                    'victories_required' => (int) $format->victories_required,
                    'format_name' => $format->name,
                    'group_round' => $groupRound,
                    'group_match' => $matchIndex + 1,
                    'status' => TeamTieStatus::Pending,
                    'is_bye' => false,
                    'winner_entry_id' => null,
                ]));
            }
        }

        return $created;
    }

    private function teamTieExistsBetweenEntries(int $groupId, int $entry1Id, int $entry2Id): bool
    {
        return TeamTie::query()
            ->where('group_id', $groupId)
            ->where(function ($query) use ($entry1Id, $entry2Id): void {
                $query->where(function ($query) use ($entry1Id, $entry2Id): void {
                    $query->where('entry1_id', $entry1Id)
                        ->where('entry2_id', $entry2Id);
                })->orWhere(function ($query) use ($entry1Id, $entry2Id): void {
                    $query->where('entry1_id', $entry2Id)
                        ->where('entry2_id', $entry1Id);
                });
            })
            ->exists();
    }
}
