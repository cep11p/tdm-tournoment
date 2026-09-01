<?php

namespace Tests\Feature\Tournament;

use App\Enums\CompetitionFormat;
use App\Enums\GameStatus;
use App\Enums\TeamTieModality;
use App\Enums\TeamTieStatus;
use App\Enums\ThirdPlaceMode;
use App\Enums\TournamentStatus;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\TeamTie;
use App\Models\TeamTieGame;
use App\Models\Tournament;
use Tests\TestCase;

class TournamentClosureTeamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_closes_tournament_with_completed_team_competition(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $entries = $context->registerTeams($competition, 2, 4);
        $context->createBracket($competition)->assertCreated();

        $final = TeamTie::query()->where('round', 'Final')->sole();
        $this->winTeamTie($context, $final, $entries, (int) $entries[0]->id);

        $tournament = $competition->tournament;

        $context->closeTournament($tournament)
            ->assertOk()
            ->assertJsonPath('data.status', TournamentStatus::Finished->value);
    }

    public function test_pending_team_tie_blocks_tournament_closure(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $context->registerTeams($competition, 4, 4);
        $context->createBracket($competition)->assertCreated();

        $context->closeTournament($competition->tournament)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);
    }

    public function test_pending_third_place_team_tie_blocks_closure_message(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(
            4,
            format: CompetitionFormat::KnockoutDirect,
        );
        $competition->update(['third_place_mode' => ThirdPlaceMode::Playoff]);
        $entries = $context->registerTeams($competition, 4, 4);
        $context->createBracket($competition)->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();

        $semifinals = TeamTie::query()
            ->where('bracket_id', $bracket->id)
            ->mainBracket()
            ->where('bracket_round', 1)
            ->orderBy('bracket_match')
            ->get();

        $this->winTeamTie($context, $semifinals[0], $entries, (int) $entries[0]->id);
        $this->winTeamTie($context, $semifinals[1], $entries, (int) $entries[2]->id);
        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = TeamTie::query()->where('round', 'Final')->sole();
        $this->winTeamTie($context, $final, $entries, (int) $entries[0]->id);

        $context->closeTournament($competition->tournament)
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.tournament.0',
                sprintf(
                    'La competencia «%s» aún tiene pendiente el enfrentamiento por el tercer puesto.',
                    $competition->name,
                ),
            );
    }

    public function test_finished_team_tie_with_not_needed_rubbers_does_not_block_closure(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $entries = $context->registerTeams($competition, 2, 4);
        $context->createBracket($competition)->assertCreated();

        $final = TeamTie::query()->where('round', 'Final')->sole();

        foreach ([1, 2, 3] as $slot) {
            $this->winRubber($context, $final, $entries, $slot, (int) $entries[0]->id);
        }

        $final->refresh();
        $this->assertSame(TeamTieStatus::Finished, $final->status);

        $notNeededCount = Game::query()
            ->whereHas('teamTieGame', fn ($query) => $query->where('team_tie_id', $final->id))
            ->where('status', GameStatus::NotNeeded)
            ->count();

        $this->assertGreaterThan(0, $notNeededCount);

        $context->closeTournament($competition->tournament)->assertOk();
    }

    public function test_unused_team_competition_does_not_block_closure(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();

        $activeCompetition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $activeCompetition->update(['tournament_id' => $tournament->id]);
        $entries = $context->registerTeams($activeCompetition, 2, 4);
        $context->createBracket($activeCompetition)->assertCreated();
        $final = TeamTie::query()->where('competition_id', $activeCompetition->id)->where('round', 'Final')->sole();
        $this->winTeamTie($context, $final, $entries, (int) $entries[0]->id);

        $unusedCompetition = $context->createTeamCompetition(4);
        $unusedCompetition->update(['tournament_id' => $tournament->id]);

        $context->closeTournament($tournament)
            ->assertOk()
            ->assertJsonPath('data.results_summary.unused_competitions', 1);
    }

    public function test_singles_closure_remains_unchanged(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);

        $context->closeTournament($setup['competition']->tournament)->assertOk();
    }

    /**
     * @param  list<\App\Models\CompetitionEntry>  $entries
     */
    private function winTeamTie(
        \Tests\Support\TournamentTestContext $context,
        TeamTie $teamTie,
        array $entries,
        int $winnerEntryId,
    ): void {
        foreach ([1, 2, 3] as $slot) {
            $this->winRubber($context, $teamTie->fresh(), $entries, $slot, $winnerEntryId);
        }
    }

    /**
     * @param  list<\App\Models\CompetitionEntry>  $entries
     */
    private function winRubber(
        \Tests\Support\TournamentTestContext $context,
        TeamTie $teamTie,
        array $entries,
        int $slotOrder,
        int $winnerEntryId,
    ): void {
        $rubber = $this->rubberAt($teamTie, $slotOrder);
        $this->lineupRubber($context, $rubber, $entries);
        $context->finishGameByEntryViaApi($rubber->game->fresh(), $winnerEntryId)->assertOk();
    }

    private function rubberAt(TeamTie $teamTie, int $slotOrder): TeamTieGame
    {
        return $teamTie->teamTieGames()->where('slot_order', $slotOrder)->firstOrFail();
    }

    /**
     * @param  list<\App\Models\CompetitionEntry>  $entries
     */
    private function lineupRubber(
        \Tests\Support\TournamentTestContext $context,
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
    private function playerIds(\App\Models\CompetitionEntry $entry, int $count): array
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
