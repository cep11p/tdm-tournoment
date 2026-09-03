<?php

namespace App\Actions\Ranking;

use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionType;
use App\Models\Competition;
use App\Models\CompetitionFinalStanding;
use App\Models\Ranking;
use App\Models\RankingTransaction;
use App\Support\Ranking\ApplicableRankingsResolver;
use App\Support\Ranking\PlayerRankingDisplayName;
use App\Support\Ranking\RankingPointsResolver;
use App\Support\Ranking\RankingRecipientsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AwardRankingFromFinalStandingsAction
{
    public const MISSING_STANDINGS_MESSAGE = 'La clasificación final no está consolidada.';

    public function __construct(
        private readonly ApplicableRankingsResolver $applicableRankingsResolver,
        private readonly RankingRecipientsResolver $recipientsResolver,
        private readonly RankingPointsResolver $pointsResolver,
        private readonly RebuildRankingStandingsAction $rebuildStandings,
    ) {}

    public function __invoke(Competition $competition): void
    {
        DB::transaction(function () use ($competition): void {
            $competition = Competition::query()
                ->with(['tournament', 'finalStandings.entry.members.player'])
                ->lockForUpdate()
                ->findOrFail($competition->id);

            $staleRankingIds = RankingTransaction::query()
                ->where('competition_id', $competition->id)
                ->orderBy('ranking_id')
                ->pluck('ranking_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($competition->isTeam()) {
                $this->replaceTransactions($competition, $staleRankingIds, []);

                return;
            }

            $standings = $competition->finalStandings
                ->sortBy([
                    ['position', 'asc'],
                    ['competition_entry_id', 'asc'],
                ])
                ->values();

            if ($standings->isEmpty()) {
                throw ValidationException::withMessages([
                    'competition' => [self::MISSING_STANDINGS_MESSAGE],
                ]);
            }

            $applicable = $this->applicableRankingsResolver->resolve($competition);
            $applicableIds = $applicable->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $affectedIds = array_values(array_unique([...$staleRankingIds, ...$applicableIds]));

            $this->replaceTransactions($competition, $affectedIds, $applicable->all(), $standings->all());
        });
    }

    /**
     * @param  list<int>  $affectedRankingIds
     * @param  list<Ranking>  $applicableRankings
     * @param  list<CompetitionFinalStanding>  $standings
     */
    private function replaceTransactions(
        Competition $competition,
        array $affectedRankingIds,
        array $applicableRankings,
        array $standings = [],
    ): void {
        if ($affectedRankingIds !== []) {
            RankingTransaction::query()
                ->where('competition_id', $competition->id)
                ->whereIn('ranking_id', $affectedRankingIds)
                ->delete();
        }

        $type = $competition->type instanceof CompetitionType
            ? $competition->type
            : CompetitionType::from((string) $competition->type);

        usort(
            $applicableRankings,
            fn (Ranking $left, Ranking $right): int => (int) $left->id <=> (int) $right->id,
        );

        foreach ($applicableRankings as $ranking) {
            $ranking->loadMissing('rules');

            foreach ($standings as $standing) {
                $recipients = $this->recipientsResolver->resolve($standing);
                $match = $this->pointsResolver->resolve($ranking, $standing, $ranking->rules);
                $source = $standing->source instanceof CompetitionFinalStandingSource
                    ? $standing->source->value
                    : (string) $standing->source;

                foreach ($recipients as $player) {
                    RankingTransaction::query()->create([
                        'ranking_id' => $ranking->id,
                        'competition_id' => $competition->id,
                        'player_id' => $player->id,
                        'ranking_rule_id' => $match->rule?->id,
                        'competition_type' => $type->value,
                        'category_id' => $competition->category_id,
                        'position' => (int) $standing->position,
                        'position_range_end' => (int) $standing->position_range_end,
                        'source' => $source,
                        'points' => $match->points,
                        'player_display_name_snapshot' => PlayerRankingDisplayName::for($player),
                        'competition_name_snapshot' => (string) $competition->name,
                        'ranking_rule_name_snapshot' => $match->rule?->name,
                    ]);
                }
            }
        }

        $rebuildIds = $affectedRankingIds;

        sort($rebuildIds);

        foreach ($rebuildIds as $rankingId) {
            $ranking = Ranking::query()->find($rankingId);

            if ($ranking instanceof Ranking) {
                ($this->rebuildStandings)($ranking);
            }
        }
    }
}
