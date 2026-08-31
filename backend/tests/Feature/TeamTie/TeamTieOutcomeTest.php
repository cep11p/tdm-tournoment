<?php

namespace Tests\Feature\TeamTie;

use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Enums\TeamTieStatus;
use App\Enums\TeamTieModality;
use App\Models\CompetitionEntryMember;
use App\Models\TeamTie;
use App\Models\TeamTieGame;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TeamTieOutcomeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_first_set_moves_team_tie_to_in_progress(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();
        $rubber = $this->rubberAt($teamTie, 1);

        $this->lineupRubber($context, $rubber, $entries);
        $context->recordSet($rubber->game, 1, 11, 5)->assertOk();

        $teamTie->refresh();
        $this->assertSame(TeamTieStatus::InProgress, $teamTie->status);
    }

    public function test_three_one_closes_team_tie_and_marks_remaining_not_needed(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();

        $this->winRubber($context, $teamTie, $entries, 1, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 2, (int) $teamTie->entry2_id);
        $this->winRubber($context, $teamTie, $entries, 3, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 4, (int) $teamTie->entry1_id);

        $teamTie->refresh();
        $slotFive = $this->rubberAt($teamTie, 5)->game->fresh();

        $this->assertSame(TeamTieStatus::Finished, $teamTie->status);
        $this->assertSame((int) $teamTie->entry1_id, (int) $teamTie->winner_entry_id);
        $this->assertNotNull($teamTie->finished_at);
        $this->assertSame(GameStatus::NotNeeded, $slotFive->status);

        $context->showTeamTie($teamTie)
            ->assertOk()
            ->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.score.entry1', 3)
            ->assertJsonPath('data.score.entry2', 1)
            ->assertJsonPath('data.winner.competition_entry_id', (int) $teamTie->entry1_id);
    }

    public function test_cannot_record_set_on_not_needed_rubber(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();

        $this->lineupRubber($context, $this->rubberAt($teamTie, 5), $entries);
        $this->winRubber($context, $teamTie, $entries, 1, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 2, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 3, (int) $teamTie->entry1_id);

        $slotFive = $this->rubberAt($teamTie->fresh(), 5);

        $context->recordSet($slotFive->game->fresh(), 1, 11, 5)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['game']);
    }

    public function test_correction_reopens_team_tie_and_restores_pending_rubber(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();

        $this->winRubber($context, $teamTie, $entries, 1, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 2, (int) $teamTie->entry2_id);
        $this->winRubber($context, $teamTie, $entries, 3, (int) $teamTie->entry1_id);
        $this->lineupRubber($context, $this->rubberAt($teamTie->fresh(), 5), $entries);
        $this->winRubber($context, $teamTie, $entries, 4, (int) $teamTie->entry1_id);

        $slotFour = $this->rubberAt($teamTie->fresh(), 4);
        $membersBefore = $this->rubberAt($teamTie->fresh(), 5)->fresh()->members()->count();

        $context->correctResult($slotFour->game->fresh(), 'Error de carga', [
            ['player1_score' => 5, 'player2_score' => 11],
        ])->assertOk();

        $teamTie->refresh();
        $slotFive = $this->rubberAt($teamTie, 5)->game->fresh();

        $this->assertSame(TeamTieStatus::InProgress, $teamTie->status);
        $this->assertNull($teamTie->winner_entry_id);
        $this->assertNull($teamTie->finished_at);
        $this->assertSame(GameStatus::Pending, $slotFive->status);
        $this->assertGreaterThan(0, $membersBefore);
        $this->assertSame($membersBefore, $this->rubberAt($teamTie, 5)->members()->count());
    }

    public function test_correction_changes_winner_without_reopening(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();

        $this->winRubber($context, $teamTie, $entries, 1, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 2, (int) $teamTie->entry2_id);
        $this->winRubber($context, $teamTie, $entries, 3, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 4, (int) $teamTie->entry2_id);
        $this->winRubber($context, $teamTie, $entries, 5, (int) $teamTie->entry1_id);

        $finishedAt = $teamTie->fresh()->finished_at;
        $slotFive = $this->rubberAt($teamTie, 5);

        $context->correctResult($slotFive->game->fresh(), 'Corrección', [
            ['player1_score' => 5, 'player2_score' => 11],
        ])->assertOk();

        $teamTie->refresh();

        $this->assertSame(TeamTieStatus::Finished, $teamTie->status);
        $this->assertSame((int) $teamTie->entry2_id, (int) $teamTie->winner_entry_id);
        $this->assertTrue($teamTie->finished_at->equalTo($finishedAt));

        $context->showTeamTie($teamTie)
            ->assertOk()
            ->assertJsonPath('data.score.entry1', 2)
            ->assertJsonPath('data.score.entry2', 3);
    }

    public function test_out_of_order_results_follow_official_slot_order_score(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();

        $this->winRubber($context, $teamTie, $entries, 4, (int) $teamTie->entry2_id);
        $this->winRubber($context, $teamTie, $entries, 5, (int) $teamTie->entry2_id);
        $this->winRubber($context, $teamTie, $entries, 1, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 2, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 3, (int) $teamTie->entry1_id);

        $context->showTeamTie($teamTie->fresh())
            ->assertOk()
            ->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.score.entry1', 3)
            ->assertJsonPath('data.score.entry2', 0)
            ->assertJsonPath('data.winner.competition_entry_id', (int) $teamTie->entry1_id);
    }

    public function test_finished_audit_emitted_once_and_idempotent_recalc_is_silent(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();

        $this->winRubber($context, $teamTie, $entries, 1, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 2, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 3, (int) $teamTie->entry1_id);

        $finishedEvents = Activity::query()
            ->where('description', AuditAction::TEAM_TIE_FINISHED->value)
            ->count();
        $this->assertSame(1, $finishedEvents);

        app(\App\Actions\TeamTie\RecalculateTeamTieOutcomeAction::class)($teamTie->fresh());

        $this->assertSame(1, Activity::query()
            ->where('description', AuditAction::TEAM_TIE_FINISHED->value)
            ->count());
    }

    public function test_reopened_and_result_changed_audits(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();

        $this->winRubber($context, $teamTie, $entries, 1, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 2, (int) $teamTie->entry2_id);
        $this->winRubber($context, $teamTie, $entries, 3, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 4, (int) $teamTie->entry1_id);

        $context->correctResult($this->rubberAt($teamTie->fresh(), 4)->game->fresh(), 'Reapertura', [
            ['player1_score' => 5, 'player2_score' => 11],
        ])->assertOk();

        $this->assertSame(1, Activity::query()
            ->where('description', AuditAction::TEAM_TIE_REOPENED->value)
            ->count());

        $this->winRubber($context, $teamTie->fresh(), $entries, 5, (int) $teamTie->entry1_id);

        $context->correctResult($this->rubberAt($teamTie->fresh(), 5)->game->fresh(), 'Cambio winner', [
            ['player1_score' => 5, 'player2_score' => 11],
        ])->assertOk();

        $this->assertSame(1, Activity::query()
            ->where('description', AuditAction::TEAM_TIE_RESULT_CHANGED->value)
            ->count());
    }

    public function test_same_winner_correction_does_not_emit_result_changed(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();

        $this->winRubber($context, $teamTie, $entries, 1, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 2, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 3, (int) $teamTie->entry1_id);

        $context->correctResult($this->rubberAt($teamTie->fresh(), 1)->game->fresh(), 'Ajuste score', [
            ['player1_score' => 11, 'player2_score' => 9],
        ])->assertOk();

        $this->assertSame(0, Activity::query()
            ->where('description', AuditAction::TEAM_TIE_RESULT_CHANGED->value)
            ->count());
    }

    public function test_all_team_ties_finished_marks_competition_ready_for_bracket(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();

        $teamTie = TeamTie::query()->where('group_id', $group->id)->firstOrFail();

        foreach ([1, 2, 3] as $slot) {
            $this->winRubber($context, $teamTie, $entries, $slot, (int) $teamTie->entry1_id);
        }

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'ready_for_bracket');
    }

    /**
     * @return array{0: TeamTie, 1: list<\App\Models\CompetitionEntry>}
     */
    private function createScheduledTeamTie(): array
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();

        $teamTie = TeamTie::query()->where('group_id', $group->id)->firstOrFail();

        return [$teamTie, $entries];
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
        $requiredPerSide = $rubber->modality === TeamTieModality::Doubles ? 2 : 1;

        $context->setTeamTieGameLineup($rubber, [
            'entry1_player_ids' => $this->playerIds($entries[0], $requiredPerSide),
            'entry2_player_ids' => $this->playerIds($entries[1], $requiredPerSide),
        ])->assertOk();
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
        $rubber = $this->rubberAt($teamTie->fresh(), $slotOrder);
        $this->lineupRubber($context, $rubber, $entries);
        $context->finishGameByEntryViaApi($rubber->game->fresh(), $winnerEntryId)->assertOk();
    }

    private function firstPlayerId(\App\Models\CompetitionEntry $entry): int
    {
        return (int) CompetitionEntryMember::query()
            ->where('competition_entry_id', $entry->id)
            ->orderBy('member_order')
            ->value('player_id');
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
