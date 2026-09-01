<?php

namespace Tests\Feature\Game;

use App\Models\CompetitionEntryMember;
use App\Models\TeamTie;
use App\Models\TeamTieGame;
use Tests\TestCase;

class GameResultCorrectionTeamGroupTest extends TestCase
{
    private const REASON = 'Corrección de prueba';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_allows_team_group_rubber_correction_before_bracket(): void
    {
        $setup = $this->createCompletedGroupPhase();
        $context = $setup['context'];
        $group = $setup['groupA'];
        $entries = $setup['entries'];

        $teamTie = TeamTie::query()
            ->where('group_id', $group->id)
            ->where('entry1_id', $entries[0]->id)
            ->where('entry2_id', $entries[1]->id)
            ->firstOrFail();

        $this->winTeamTieThreeTwo($context, $teamTie, $entries, (int) $entries[0]->id);

        $rubber = $this->rubberAt($teamTie->fresh(), 5);

        $context->correctResult($rubber->game->fresh(), self::REASON, [
            ['player1_score' => 5, 'player2_score' => 11],
        ])->assertOk();

        $teamTie->refresh();
        $this->assertSame((int) $entries[1]->id, (int) $teamTie->winner_entry_id);
    }

    public function test_blocks_team_group_rubber_correction_after_bracket(): void
    {
        $setup = $this->createCompletedGroupPhase();
        $context = $setup['context'];
        $group = $setup['groupA'];
        $entries = $setup['entries'];

        $this->finishGroupWinner($context, $setup['groupA'], $entries, $entries[0]);
        $this->finishGroupWinner($context, $setup['groupB'], $entries, $entries[2]);

        $context->createBracket($setup['competition'], 1)->assertCreated();

        $teamTie = TeamTie::query()
            ->where('group_id', $group->id)
            ->where('entry1_id', $entries[0]->id)
            ->where('entry2_id', $entries[1]->id)
            ->firstOrFail();

        $rubber = $this->rubberAt($teamTie->fresh(), 1);

        $context->correctResult($rubber->game->fresh(), self::REASON, [
            ['player1_score' => 5, 'player2_score' => 11],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['game'])
            ->assertJsonPath(
                'errors.game.0',
                'No se puede corregir el resultado porque la llave ya fue generada.',
            );
    }

    public function test_rejects_team_rubber_correction_when_tournament_is_closed(): void
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

        $setup['competition']->tournament->update([
            'status' => \App\Enums\TournamentStatus::Finished,
            'closed_at' => now(),
        ]);

        $rubber = $this->rubberAt($semifinals[0]->fresh(), 5);

        $context->correctResult($rubber->game->fresh(), self::REASON, [
            ['player1_score' => 5, 'player2_score' => 11],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);
    }

    /**
     * @return array{
     *     context: \Tests\Support\TournamentTestContext,
     *     competition: \App\Models\Competition,
     *     entries: list<\App\Models\CompetitionEntry>,
     *     groupA: \App\Models\Group,
     *     groupB: \App\Models\Group,
     * }
     */
    private function createCompletedGroupPhase(): array
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 4, 4);
        $groupA = $context->createGroupWithEntries($competition, [$entries[0], $entries[1]], 'Grupo A');
        $groupB = $context->createGroupWithEntries($competition, [$entries[2], $entries[3]], 'Grupo B');

        $context->generateTeamRoundRobin($groupA)->assertCreated();
        $context->generateTeamRoundRobin($groupB)->assertCreated();

        return compact('context', 'competition', 'entries', 'groupA', 'groupB');
    }

    /**
     * @return array{
     *     context: \Tests\Support\TournamentTestContext,
     *     competition: \App\Models\Competition,
     *     entries: list<\App\Models\CompetitionEntry>,
     *     bracket: \App\Models\Bracket,
     * }
     */
    private function createDirectFourTeamBracket(): array
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: \App\Enums\CompetitionFormat::KnockoutDirect);
        $entries = $context->registerTeams($competition, 4, 4);
        $context->createBracket($competition)->assertCreated();
        $bracket = \App\Models\Bracket::query()->where('competition_id', $competition->id)->sole();

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
        $requiredPerSide = $rubber->modality === \App\Enums\TeamTieModality::Doubles ? 2 : 1;

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
