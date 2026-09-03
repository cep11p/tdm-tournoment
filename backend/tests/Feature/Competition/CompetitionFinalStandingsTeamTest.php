<?php

namespace Tests\Feature\Competition;

use App\Actions\Competition\PersistCompetitionFinalStandingsAction;
use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionFormat;
use App\Enums\TeamTieModality;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\CompetitionFinalStanding;
use App\Models\TeamTie;
use App\Models\TeamTieGame;
use Database\Seeders\DemoPlayersSeeder;
use Database\Seeders\DemoTournamentSeeder;
use Database\Seeders\Support\Scenarios\TeamKnockoutInProgressScenario;
use Database\Seeders\TeamTieFormatSeeder;
use Illuminate\Validation\ValidationException;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class CompetitionFinalStandingsTeamTest extends TestCase
{
    public function test_team_knockout_persists_entry_centric_standings(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $entries = $context->registerTeams($competition, 2, 4);
        $context->createBracket($competition)->assertCreated();

        $final = TeamTie::query()->where('competition_id', $competition->id)->where('round', 'Final')->sole();
        $this->winTeamTie($context, $final, $entries, (int) $entries[0]->id);

        $standings = $competition->fresh()->finalStandings()->orderBy('position')->get();

        $this->assertCount(2, $standings);
        $this->assertSame((int) $entries[0]->id, (int) $standings[0]->competition_entry_id);
        $this->assertSame(1, (int) $standings[0]->position);
        $this->assertSame((int) $entries[1]->id, (int) $standings[1]->competition_entry_id);
        $this->assertSame(2, (int) $standings[1]->position);
        $this->assertSame(CompetitionFinalStandingSource::Final, $standings[0]->source);
        $this->assertSame($entries[0]->display_name, $standings[0]->display_name_snapshot);

        $payload = $this->getJson($context->apiUrl("competitions/{$competition->id}/final-standings"))
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('player_id', $payload[0]);
        $this->assertSame((int) $entries[0]->id, $payload[0]['competition_entry_id']);
    }

    public function test_seed_six_team_in_progress_rejects_persist(): void
    {
        $this->seed([
            TeamTieFormatSeeder::class,
            DemoPlayersSeeder::class,
            DemoTournamentSeeder::class,
        ]);

        $competition = \App\Models\Competition::query()
            ->where('name', TeamKnockoutInProgressScenario::COMPETITION_NAME)
            ->firstOrFail();

        try {
            app(PersistCompetitionFinalStandingsAction::class)($competition);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame(
                PersistCompetitionFinalStandingsAction::INCOMPLETE_MESSAGE,
                $exception->errors()['competition'][0],
            );
        }

        $this->assertSame(0, CompetitionFinalStanding::query()->where('competition_id', $competition->id)->count());
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
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
