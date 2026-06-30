<?php

namespace App\Actions\Group;

use App\Models\Competition;
use App\Support\Competition\CompetitionFormatGuard;
use App\Support\Competition\CompetitionStructureGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GenerateRandomGroupsForCompetitionAction
{
    public function __construct(
        private readonly BuildRandomGroupsForCompetitionAction $buildRandomGroups,
    ) {}

    /**
     * @return array{
     *     groups_created: int,
     *     players_assigned: int,
     *     games_created: int,
     *     groups: \Illuminate\Support\Collection<int, \App\Models\Group>,
     * }
     */
    public function __invoke(Competition $competition, int $groupsCount): array
    {
        CompetitionFormatGuard::ensureGroupStage($competition);
        CompetitionStructureGuard::ensureEditable($competition);

        if ($competition->groups()->exists()) {
            throw ValidationException::withMessages([
                'competition' => ['La competencia ya tiene grupos configurados.'],
            ]);
        }

        return DB::transaction(fn (): array => ($this->buildRandomGroups)($competition, $groupsCount));
    }
}
