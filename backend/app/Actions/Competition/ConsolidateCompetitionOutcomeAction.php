<?php

namespace App\Actions\Competition;

use App\Actions\Ranking\AwardRankingFromFinalStandingsAction;
use App\Models\Competition;
use App\Models\CompetitionFinalStanding;
use App\Support\Competition\CompetitionStatusResolver;
use Illuminate\Support\Facades\DB;

final class ConsolidateCompetitionOutcomeAction
{
    public function __construct(
        private readonly PersistCompetitionFinalStandingsAction $persistFinalStandings,
        private readonly AwardRankingFromFinalStandingsAction $awardRanking,
    ) {}

    /**
     * @return list<CompetitionFinalStanding>
     */
    public function __invoke(Competition $competition): array
    {
        return DB::transaction(function () use ($competition): array {
            $standings = ($this->persistFinalStandings)($competition);
            ($this->awardRanking)($competition);

            return $standings;
        });
    }

    public function persistIfCompleted(Competition $competition): void
    {
        $status = CompetitionStatusResolver::resolve($competition->fresh());

        if ($status['code'] !== 'completed') {
            return;
        }

        $this($competition);
    }
}
