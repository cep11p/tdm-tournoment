<?php

namespace Tests\Feature\Ranking;

use App\Actions\Ranking\AwardRankingFromFinalStandingsAction;
use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use App\Enums\TeamTieModality;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\RankingTransaction;
use App\Models\TeamTie;
use App\Models\TeamTieGame;
use Database\Seeders\RankingSeeder;
use Tests\Support\RankingTestSetup;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class RankingTeamExclusionTest extends TestCase
{
    public function test_completed_team_competition_does_not_create_transactions(): void
    {
        $this->seed(RankingSeeder::class);
        RankingTestSetup::ranking(CompetitionType::Singles, ['name' => 'Should not apply to team']);

        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $entries = $context->registerTeams($competition, 2, 4);
        $context->createBracket($competition)->assertCreated();

        $final = TeamTie::query()->where('competition_id', $competition->id)->where('round', 'Final')->sole();
        $this->winTeamTie($context, $final, $entries, (int) $entries[0]->id);

        $this->assertGreaterThan(0, $competition->fresh()->finalStandings()->count());
        $this->assertSame(0, RankingTransaction::query()->where('competition_id', $competition->id)->count());

        app(AwardRankingFromFinalStandingsAction::class)($competition->fresh());

        $this->assertSame(0, RankingTransaction::query()->where('competition_id', $competition->id)->count());
    }

    /**
     * @param  list<CompetitionEntry>  $entries
     */
    private function winTeamTie(
        TournamentTestContext $context,
        TeamTie $teamTie,
        array $entries,
        int $winnerEntryId,
    ): void {
        foreach ([1, 2, 3] as $slot) {
            $this->winRubber($context, $teamTie->fresh(), $entries, $slot, $winnerEntryId);
        }
    }

    /**
     * @param  list<CompetitionEntry>  $entries
     */
    private function winRubber(
        TournamentTestContext $context,
        TeamTie $teamTie,
        array $entries,
        int $slotOrder,
        int $winnerEntryId,
    ): void {
        $rubber = $teamTie->teamTieGames()->where('slot_order', $slotOrder)->firstOrFail();
        $this->lineupRubber($context, $rubber, $entries);
        $context->finishGameByEntryViaApi($rubber->game->fresh(), $winnerEntryId)->assertOk();
    }

    /**
     * @param  list<CompetitionEntry>  $entries
     */
    private function lineupRubber(
        TournamentTestContext $context,
        TeamTieGame $rubber,
        array $entries,
    ): void {
        $teamTie = $rubber->teamTie()->firstOrFail();
        $entry1 = collect($entries)->firstWhere('id', $teamTie->entry1_id);
        $entry2 = collect($entries)->firstWhere('id', $teamTie->entry2_id);
        $requiredPerSide = $rubber->modality === TeamTieModality::Doubles ? 2 : 1;

        $context->setTeamTieGameLineup($rubber, [
            'entry1_player_ids' => $this->playerIds($entry1, $requiredPerSide),
            'entry2_player_ids' => $this->playerIds($entry2, $requiredPerSide),
        ])->assertOk();
    }

    /**
     * @return list<int>
     */
    private function playerIds(CompetitionEntry $entry, int $count): array
    {
        return CompetitionEntryMember::query()
            ->where('competition_entry_id', $entry->id)
            ->orderBy('member_order')
            ->limit($count)
            ->pluck('player_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
