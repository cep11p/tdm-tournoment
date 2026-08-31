<?php

namespace Tests\Feature\Bracket;

use App\Enums\BracketGamePurpose;
use App\Enums\CompetitionFormat;
use App\Enums\GameStatus;
use App\Enums\TeamTieModality;
use App\Enums\TeamTieStatus;
use App\Enums\ThirdPlaceMode;
use App\Models\Bracket;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\TeamTie;
use App\Models\TeamTieGame;
use App\Support\Competition\CompetitionResultResolver;
use App\Support\Competition\CompetitionStatusResolver;
use Tests\TestCase;

class TeamBracketTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_direct_knockout_two_teams_creates_final_team_tie(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $entries = $context->registerTeams($competition, 2, 4);

        $response = $context->createBracket($competition);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 2)
            ->assertJsonPath('data.byes_count', 0)
            ->assertJsonCount(0, 'data.games')
            ->assertJsonCount(1, 'data.team_ties');

        $teamTie = TeamTie::query()->where('competition_id', $competition->id)->sole();

        $this->assertNull($teamTie->group_id);
        $this->assertNotNull($teamTie->bracket_id);
        $this->assertSame('Final', $teamTie->round);
        $this->assertSame(5, $teamTie->teamTieGames()->count());
        $this->assertNull(Game::query()->whereNotNull('bracket_id')->first());
        $this->assertContains((int) $teamTie->entry1_id, [$entries[0]->id, $entries[1]->id]);
        $this->assertContains((int) $teamTie->entry2_id, [$entries[0]->id, $entries[1]->id]);
    }

    public function test_direct_knockout_four_teams_creates_semifinals(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $context->registerTeams($competition, 4, 4);

        $context->createBracket($competition)->assertCreated();

        $this->assertSame(2, TeamTie::query()->where('competition_id', $competition->id)->count());
        $this->assertSame(0, Game::query()->whereNotNull('bracket_id')->count());
    }

    public function test_direct_knockout_bye_has_no_rubbers(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $context->registerTeams($competition, 3, 4);

        $context->createBracket($competition)
            ->assertCreated()
            ->assertJsonPath('data.byes_count', 1);

        $byeTie = TeamTie::query()
            ->where('competition_id', $competition->id)
            ->where('is_bye', true)
            ->sole();

        $this->assertSame(TeamTieStatus::Finished, $byeTie->status);
        $this->assertSame(0, $byeTie->teamTieGames()->count());
        $this->assertNotNull($byeTie->winner_entry_id);
    }

    public function test_rubber_games_have_null_bracket_id(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $context->registerTeams($competition, 2, 4);
        $context->createBracket($competition)->assertCreated();

        $rubber = Game::query()->whereHas('teamTieGame')->first();

        $this->assertNotNull($rubber);
        $this->assertNull($rubber->bracket_id);
    }

    public function test_next_round_advances_winners_to_final(): void
    {
        $setup = $this->createDirectFourTeamBracket();
        $context = $setup['context'];
        $competition = $setup['competition'];
        $entries = $setup['entries'];
        $bracket = $setup['bracket'];

        $semifinals = TeamTie::query()
            ->where('bracket_id', $bracket->id)
            ->mainBracket()
            ->where('bracket_round', 1)
            ->orderBy('bracket_match')
            ->get();

        $this->winTeamTie($context, $semifinals[0], $entries, (int) $entries[0]->id);
        $this->winTeamTie($context, $semifinals[1], $entries, (int) $entries[2]->id);

        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = TeamTie::query()
            ->where('bracket_id', $bracket->id)
            ->where('round', 'Final')
            ->sole();

        $this->assertSame((int) $entries[0]->id, (int) $final->entry1_id);
        $this->assertSame((int) $entries[2]->id, (int) $final->entry2_id);
        $this->assertSame(5, $final->teamTieGames()->count());
    }

    public function test_bye_winner_advances_on_next_round(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $entries = $context->registerTeams($competition, 3, 4);
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();

        $realSemifinal = TeamTie::query()
            ->where('bracket_id', $bracket->id)
            ->where('is_bye', false)
            ->sole();

        $this->winTeamTie($context, $realSemifinal, $entries, (int) $entries[1]->id);

        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = TeamTie::query()->where('round', 'Final')->sole();

        $this->assertContains((int) $final->entry1_id, [(int) $entries[0]->id, (int) $entries[1]->id]);
        $this->assertContains((int) $final->entry2_id, [(int) $entries[0]->id, (int) $entries[1]->id]);
    }

    public function test_playoff_third_place_created_with_semifinal_losers(): void
    {
        $setup = $this->createDirectFourTeamBracket(ThirdPlaceMode::Playoff);
        $context = $setup['context'];
        $entries = $setup['entries'];
        $bracket = $setup['bracket'];

        $semifinals = TeamTie::query()
            ->where('bracket_id', $bracket->id)
            ->mainBracket()
            ->where('bracket_round', 1)
            ->orderBy('bracket_match')
            ->get();

        $this->winTeamTie($context, $semifinals[0], $entries, (int) $entries[0]->id);
        $this->winTeamTie($context, $semifinals[1], $entries, (int) $entries[2]->id);

        $context->generateBracketNextRound($bracket)->assertCreated();

        $thirdPlace = TeamTie::query()
            ->where('bracket_id', $bracket->id)
            ->thirdPlace()
            ->sole();

        $participantIds = [(int) $thirdPlace->entry1_id, (int) $thirdPlace->entry2_id];
        $this->assertContains((int) $entries[1]->id, $participantIds);
        $this->assertContains((int) $entries[3]->id, $participantIds);
        $this->assertSame('Tercer puesto', $thirdPlace->round);
    }

    public function test_shared_third_place_does_not_create_team_tie(): void
    {
        $setup = $this->createDirectFourTeamBracket(ThirdPlaceMode::Shared);
        $context = $setup['context'];
        $entries = $setup['entries'];
        $bracket = $setup['bracket'];

        $semifinals = TeamTie::query()
            ->where('bracket_id', $bracket->id)
            ->mainBracket()
            ->where('bracket_round', 1)
            ->orderBy('bracket_match')
            ->get();

        $this->winTeamTie($context, $semifinals[0], $entries, (int) $entries[0]->id);
        $this->winTeamTie($context, $semifinals[1], $entries, (int) $entries[2]->id);

        $context->generateBracketNextRound($bracket)->assertCreated();

        $this->assertNull(
            TeamTie::query()->where('bracket_id', $bracket->id)->thirdPlace()->first(),
        );
    }

    public function test_direct_knockout_completion_resolves_podium(): void
    {
        $setup = $this->createDirectTwoTeamBracket();
        $context = $setup['context'];
        $competition = $setup['competition'];
        $entries = $setup['entries'];

        $final = TeamTie::query()->where('round', 'Final')->sole();
        $this->winTeamTie($context, $final, $entries, (int) $entries[0]->id);

        $result = CompetitionResultResolver::resolve($competition->fresh());

        $this->assertNotNull($result);
        $this->assertSame((int) $entries[0]->id, $result['champion']['competition_entry_id']);
        $this->assertSame((int) $entries[1]->id, $result['runner_up']['competition_entry_id']);
        $this->assertSame($final->id, $result['final_team_tie_id']);
        $this->assertNull($result['final_game_id']);
        $this->assertNull($result['champion']['id']);
        $this->assertNotEmpty($result['champion']['display_name']);
        $this->assertNotEmpty($result['champion']['members']);

        $status = CompetitionStatusResolver::resolve($competition->fresh());
        $this->assertSame('completed', $status['code']);
    }

    public function test_semifinal_correction_updates_final_before_start(): void
    {
        $setup = $this->createDirectFourTeamBracket();
        $context = $setup['context'];
        $entries = $setup['entries'];
        $bracket = $setup['bracket'];

        $semifinals = TeamTie::query()
            ->where('bracket_id', $bracket->id)
            ->mainBracket()
            ->where('bracket_round', 1)
            ->orderBy('bracket_match')
            ->get();

        $this->winTeamTieThreeTwo($context, $semifinals[0], $entries, (int) $entries[0]->id);
        $this->winTeamTie($context, $semifinals[1], $entries, (int) $entries[3]->id);

        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = TeamTie::query()->where('round', 'Final')->sole();
        $this->assertSame((int) $entries[0]->id, (int) $final->entry1_id);

        $slotFive = $this->rubberAt($semifinals[0]->fresh(), 5);
        $context->correctResult($slotFive->game->fresh(), 'Corrección', [
            ['player1_score' => 5, 'player2_score' => 11],
        ])->assertOk();

        $final->refresh();
        $this->assertSame((int) $entries[3]->id, (int) $final->entry1_id);
        $this->assertSame(5, $final->teamTieGames()->count());
    }

    public function test_semifinal_correction_blocked_after_final_started(): void
    {
        $setup = $this->createDirectFourTeamBracket();
        $context = $setup['context'];
        $entries = $setup['entries'];
        $bracket = $setup['bracket'];

        $semifinals = TeamTie::query()
            ->where('bracket_id', $bracket->id)
            ->mainBracket()
            ->where('bracket_round', 1)
            ->orderBy('bracket_match')
            ->get();

        $this->winTeamTieThreeTwo($context, $semifinals[0], $entries, (int) $entries[0]->id);
        $this->winTeamTie($context, $semifinals[1], $entries, (int) $entries[3]->id);
        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = TeamTie::query()->where('round', 'Final')->sole();
        $rubber = $this->rubberAt($final, 1);
        $this->lineupRubber($context, $rubber, $entries);

        $slotFive = $this->rubberAt($semifinals[0]->fresh(), 5);
        $context->correctResult($slotFive->game->fresh(), 'Corrección', [
            ['player1_score' => 5, 'player2_score' => 11],
        ])->assertUnprocessable();
    }

    public function test_groups_knockout_team_bracket_from_qualifiers(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 4, 4);
        $groupA = $context->createGroupWithEntries($competition, [$entries[0], $entries[1]], 'Grupo A');
        $groupB = $context->createGroupWithEntries($competition, [$entries[2], $entries[3]], 'Grupo B');

        $context->generateTeamRoundRobin($groupA)->assertCreated();
        $context->generateTeamRoundRobin($groupB)->assertCreated();

        $this->finishGroupWinner($context, $groupA, $entries, $entries[0]);
        $this->finishGroupWinner($context, $groupB, $entries, $entries[2]);

        $context->createBracket($competition, 1)
            ->assertCreated()
            ->assertJsonCount(1, 'data.team_ties');

        $teamTie = TeamTie::query()->whereNotNull('bracket_id')->sole();
        $this->assertContains((int) $teamTie->entry1_id, [(int) $entries[0]->id, (int) $entries[2]->id]);
        $this->assertContains((int) $teamTie->entry2_id, [(int) $entries[0]->id, (int) $entries[2]->id]);
    }

    /**
     * @return array{
     *     context: \Tests\Support\TournamentTestContext,
     *     competition: \App\Models\Competition,
     *     entries: list<\App\Models\CompetitionEntry>,
     *     bracket: Bracket,
     * }
     */
    private function createDirectTwoTeamBracket(): array
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $entries = $context->registerTeams($competition, 2, 4);
        $context->createBracket($competition)->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();

        return compact('context', 'competition', 'entries', 'bracket');
    }

    /**
     * @return array{
     *     context: \Tests\Support\TournamentTestContext,
     *     competition: \App\Models\Competition,
     *     entries: list<\App\Models\CompetitionEntry>,
     *     bracket: Bracket,
     * }
     */
    private function createDirectFourTeamBracket(ThirdPlaceMode $thirdPlaceMode = ThirdPlaceMode::None): array
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $competition->update(['third_place_mode' => $thirdPlaceMode]);
        $entries = $context->registerTeams($competition, 4, 4);
        $context->createBracket($competition)->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();

        return compact('context', 'competition', 'entries', 'bracket');
    }

    /**
     * @param  list<\App\Models\CompetitionEntry>  $entries
     */
    private function finishGroupWinner(
        \Tests\Support\TournamentTestContext $context,
        \App\Models\Group $group,
        array $entries,
        \App\Models\CompetitionEntry $winner,
    ): void {
        $teamTies = TeamTie::query()->where('group_id', $group->id)->get();

        foreach ($teamTies as $teamTie) {
            $winnerId = (int) $winner->id === (int) $teamTie->entry1_id
                || (int) $winner->id === (int) $teamTie->entry2_id
                ? (int) $winner->id
                : (int) $teamTie->entry1_id;

            $this->winTeamTie($context, $teamTie, $entries, $winnerId);
        }
    }

    /**
     * @param  list<\App\Models\CompetitionEntry>  $entries
     */
    private function winTeamTieThreeTwo(
        \Tests\Support\TournamentTestContext $context,
        TeamTie $teamTie,
        array $entries,
        int $winnerEntryId,
    ): void {
        $loserEntryId = (int) $teamTie->entry1_id === $winnerEntryId
            ? (int) $teamTie->entry2_id
            : (int) $teamTie->entry1_id;

        $this->winRubber($context, $teamTie, $entries, 1, $winnerEntryId);
        $this->winRubber($context, $teamTie, $entries, 2, $loserEntryId);
        $this->winRubber($context, $teamTie, $entries, 3, $winnerEntryId);
        $this->winRubber($context, $teamTie, $entries, 4, $loserEntryId);
        $this->winRubber($context, $teamTie, $entries, 5, $winnerEntryId);
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
