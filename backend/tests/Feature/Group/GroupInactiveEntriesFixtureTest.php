<?php

namespace Tests\Feature\Group;

use App\Actions\Group\BuildPrintGroupSheetAction;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Enums\GroupPlayerStatus;
use App\Enums\TeamTieModality;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupEntry;
use App\Models\TeamTie;
use App\Models\TeamTieGame;
use App\Support\Group\GroupScheduleCompletion;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class GroupInactiveEntriesFixtureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_singles_round_robin_excludes_entry_withdrawn_before_fixture(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $this->markInactive($context, $group, $entries[3]);
        Activity::query()->delete();

        $context->generateRoundRobin($group)->assertCreated();

        $this->assertSame(3, $group->games()->count());
        $this->assertSame(0, $this->gamesInvolving($group, $entries[3])->count());
        $this->assertTrue(GroupScheduleCompletion::hasCompleteGamesSchedule($group->fresh()));

        $activity = Activity::query()
            ->where('description', AuditAction::GROUPS_ROUND_ROBIN_GENERATED->value)
            ->sole();
        $this->assertSame(3, data_get($activity->properties, 'summary.games_created'));
        $this->assertSame(4, data_get($activity->properties, 'summary.player_count'));
    }

    public function test_doubles_round_robin_excludes_pair_disqualified_before_fixture(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(6);
        $entries = $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
        ]);
        $group = $context->createGroupWithEntries($competition, $entries);
        $this->markInactive($context, $group, $entries[0], 'disqualified');

        $context->generateRoundRobin($group)->assertCreated();

        $game = $group->games()->sole();
        $this->assertEqualsCanonicalizing(
            [$entries[1]->id, $entries[2]->id],
            [(int) $game->entry1_id, (int) $game->entry2_id],
        );
    }

    public function test_generation_is_rejected_with_a_single_active_participant(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 2);
        $group = $context->createGroupWithEntries($competition, $entries);
        $this->markInactive($context, $group, $entries[0]);

        $context->generateRoundRobin($group)
            ->assertUnprocessable()
            ->assertJsonPath('errors.group.0', 'El grupo necesita al menos 2 jugadores activos.');

        $this->assertSame(0, $group->games()->count());
    }

    public function test_withdrawal_after_fixture_keeps_historical_games_on_incremental_assignment(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 4);
        $group = $context->createGroupWithEntries($competition, array_slice($entries, 0, 3));
        $context->generateRoundRobin($group)->assertCreated();

        $playedGame = $context->findGameBetweenEntries($group->games()->get(), $entries[0]->id, $entries[1]->id);
        $context->finishGameByEntry($playedGame, $entries[0]->id);
        $this->markInactive($context, $group, $entries[0]);

        $context->assignEntryToGroupViaApi($group, $entries[3])->assertCreated();

        $this->assertSame(5, $group->games()->count());
        $this->assertSame(0, $this->gamesInvolving($group, $entries[3])
            ->filter(fn (Game $game): bool => in_array($entries[0]->id, [(int) $game->entry1_id, (int) $game->entry2_id], true))
            ->count());

        $historicalGames = $this->gamesInvolving($group, $entries[0]);
        $this->assertCount(2, $historicalGames);
        $this->assertTrue($historicalGames->every(fn (Game $game): bool => $game->status === GameStatus::Finished));
        $this->assertSame($entries[0]->id, (int) $playedGame->fresh()->winner_entry_id);
        $this->assertTrue(GroupScheduleCompletion::hasCompleteGamesSchedule($group->fresh()));
    }

    public function test_moving_entry_withdrawn_before_fixture_does_not_create_games_in_target(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 6);
        $groupA = $context->createGroupWithEntries($competition, array_slice($entries, 0, 3), 'Grupo A');
        $groupB = $context->createGroupWithEntries($competition, array_slice($entries, 3, 3), 'Grupo B');
        $context->generateRoundRobin($groupA)->assertCreated();
        $this->markInactive($context, $groupB, $entries[5]);
        $context->generateRoundRobin($groupB)->assertCreated();

        $context->moveEntryBetweenGroupsViaApi($groupB, $entries[5]->id, $groupA)->assertOk();

        $this->assertSame(3, $groupA->games()->count());
        $this->assertSame(1, $groupB->games()->count());
        $this->assertSame(0, Game::query()
            ->where('entry1_id', $entries[5]->id)
            ->orWhere('entry2_id', $entries[5]->id)
            ->count());
        $this->assertSame(
            GroupPlayerStatus::Withdrawn,
            GroupEntry::query()->where('competition_entry_id', $entries[5]->id)->sole()->status,
        );
    }

    public function test_removing_entry_withdrawn_before_fixture_keeps_fixture(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $this->markInactive($context, $group, $entries[3]);
        $context->generateRoundRobin($group)->assertCreated();
        $gameIds = $group->games()->orderBy('id')->pluck('id')->all();

        $context->removeEntryFromGroupViaApi($group, $entries[3])->assertNoContent();

        $this->assertSame($gameIds, $group->games()->orderBy('id')->pluck('id')->all());
    }

    public function test_schedule_completion_requires_every_active_pair(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateRoundRobin($group)->assertCreated();
        $this->markInactive($context, $group, $entries[0]);

        // 6 partidos: 3 entre activos + 3 históricos contra el retirado.
        $this->assertTrue(GroupScheduleCompletion::hasCompleteGamesSchedule($group->fresh()));

        $context->findGameBetweenEntries($group->games()->get(), $entries[1]->id, $entries[2]->id)->delete();

        $this->assertFalse(GroupScheduleCompletion::hasCompleteGamesSchedule($group->fresh()));
    }

    public function test_bracket_qualifies_single_active_participant_without_games(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 5);
        $groupA = $context->createGroupWithEntries($competition, array_slice($entries, 0, 3), 'Grupo A');
        $groupB = $context->createGroupWithEntries($competition, array_slice($entries, 3, 2), 'Grupo B');
        $context->generateRoundRobin($groupA)->assertCreated();
        $this->finishGroupGames($context, $groupA);
        $this->markInactive($context, $groupB, $entries[4]);

        $context->createBracket($competition, qualifiedPerGroup: 1)->assertCreated();

        $bracketEntryIds = $this->bracketEntryIds($competition);
        $this->assertContains($entries[3]->id, $bracketEntryIds);
        $this->assertNotContains($entries[4]->id, $bracketEntryIds);
        $this->assertSame(0, $groupB->games()->count());
    }

    public function test_group_without_active_participants_does_not_block_bracket(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 5);
        $groupA = $context->createGroupWithEntries($competition, array_slice($entries, 0, 3), 'Grupo A');
        $groupB = $context->createGroupWithEntries($competition, array_slice($entries, 3, 2), 'Grupo B');
        $context->generateRoundRobin($groupA)->assertCreated();
        $this->finishGroupGames($context, $groupA);
        $this->markInactive($context, $groupB, $entries[3]);
        $this->markInactive($context, $groupB, $entries[4]);

        $context->createBracket($competition, qualifiedPerGroup: 2)->assertCreated();

        $bracketEntryIds = $this->bracketEntryIds($competition);
        $this->assertCount(2, $bracketEntryIds);
        $this->assertNotContains($entries[3]->id, $bracketEntryIds);
        $this->assertNotContains($entries[4]->id, $bracketEntryIds);
    }

    public function test_bracket_still_requires_two_qualifiers_across_competition(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 4);
        $groupA = $context->createGroupWithEntries($competition, array_slice($entries, 0, 2), 'Grupo A');
        $groupB = $context->createGroupWithEntries($competition, array_slice($entries, 2, 2), 'Grupo B');
        $this->markInactive($context, $groupA, $entries[0]);
        $this->markInactive($context, $groupA, $entries[1]);
        $this->markInactive($context, $groupB, $entries[2]);

        $context->createBracket($competition, qualifiedPerGroup: 2)->assertUnprocessable();

        $this->assertFalse(Bracket::query()->where('competition_id', $competition->id)->exists());
    }

    public function test_bracket_after_pre_fixture_withdrawal_is_not_blocked_by_pending_games(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 6);
        $groupA = $context->createGroupWithEntries($competition, array_slice($entries, 0, 3), 'Grupo A');
        $groupB = $context->createGroupWithEntries($competition, array_slice($entries, 3, 3), 'Grupo B');
        $this->markInactive($context, $groupB, $entries[5]);
        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();
        $this->finishGroupGames($context, $groupA);
        $this->finishGroupGames($context, $groupB);

        $context->createBracket($competition, qualifiedPerGroup: 2)->assertCreated();

        $this->assertNotContains($entries[5]->id, $this->bracketEntryIds($competition));
    }

    public function test_print_uses_active_sheet_when_withdrawal_precedes_fixture(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $this->markInactive($context, $group, $entries[1]);
        $context->generateRoundRobin($group)->assertCreated();

        $payload = $this->getJson($context->apiUrl("groups/{$group->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame('g3', $payload['sheet_kind']);
        $this->assertCount(3, $payload['matches']);
        $this->assertCount(3, $payload['matrix']);
        $this->assertCount(4, $payload['participants']);

        $inactive = collect($payload['participants'])->firstWhere('competition_entry_id', $entries[1]->id);
        $this->assertSame('withdrawn', $inactive['status']);
        $this->assertNull($inactive['sheet_number']);
        $this->assertSame(
            [1, 2, 3],
            collect($payload['participants'])->pluck('sheet_number')->filter()->values()->all(),
        );
    }

    public function test_print_keeps_full_sheet_when_withdrawal_follows_fixture(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateRoundRobin($group)->assertCreated();
        $this->markInactive($context, $group, $entries[2], 'disqualified');

        $payload = $this->getJson($context->apiUrl("groups/{$group->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame('g4', $payload['sheet_kind']);
        $this->assertCount(6, $payload['matches']);
        $this->assertCount(4, $payload['matrix']);

        $row = collect($payload['matrix'])->firstWhere('entry.competition_entry_id', $entries[2]->id);
        $this->assertSame('disqualified', $row['entry']['status']);
        $this->assertNotNull($row['sheet_number']);
    }

    public function test_print_falls_back_to_generic_sheet_without_losing_historical_games(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 4);
        $group = $context->createGroupWithEntries($competition, array_slice($entries, 0, 3));
        $context->generateRoundRobin($group)->assertCreated();
        $this->markInactive($context, $group, $entries[0]);
        $context->assignEntryToGroupViaApi($group, $entries[3])->assertCreated();

        $payload = $this->getJson($context->apiUrl("groups/{$group->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame('generic', $payload['sheet_kind']);
        $this->assertEqualsCanonicalizing(
            $group->games()->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            collect($payload['matches'])->pluck('game_id')->all(),
        );
        $this->assertSame(
            'withdrawn',
            collect($payload['participants'])->firstWhere('competition_entry_id', $entries[0]->id)['status'],
        );
    }

    public function test_competition_print_accepts_group_reduced_by_withdrawals(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 5);
        $groupA = $context->createGroupWithEntries($competition, array_slice($entries, 0, 3), 'Grupo A');
        $groupB = $context->createGroupWithEntries($competition, array_slice($entries, 3, 2), 'Grupo B');
        $context->generateRoundRobin($groupA)->assertCreated();
        $this->markInactive($context, $groupB, $entries[4]);

        $this->getJson($context->apiUrl("competitions/{$competition->id}/groups/print"))
            ->assertOk()
            ->assertJsonPath('data.groups_count', 2);

        $this->getJson($context->apiUrl("groups/{$groupB->id}/print"))
            ->assertOk()
            ->assertJsonPath('data.sheet_kind', 'generic')
            ->assertJsonCount(0, 'data.matches');
    }

    public function test_pdf_official_sheet_marks_inactive_participants(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 5);
        $group = $context->createGroupWithEntries($competition, array_slice($entries, 0, 4));
        $this->markInactive($context, $group, $entries[3]);
        $context->generateRoundRobin($group)->assertCreated();

        $html = $this->renderSheet($group);

        $this->assertStringContainsString('data-sheet-kind="g3"', $html);
        $this->assertSame(3, substr_count($html, 'official-matrix-row'));
        $this->assertStringContainsString('Bajas sin partidos:', $html);
        $this->assertStringContainsString('(Retirado)', $html);
    }

    public function test_pdf_full_sheet_marks_participant_withdrawn_after_fixture(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $entries = $this->registerSinglesEntries($context, $competition, 3);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateRoundRobin($group)->assertCreated();
        $this->markInactive($context, $group, $entries[0], 'disqualified');

        $html = $this->renderSheet($group);

        $this->assertStringContainsString('data-sheet-kind="g3"', $html);
        $this->assertSame(3, substr_count($html, 'official-matrix-row'));
        $this->assertStringContainsString('(Descalificado)', $html);
        $this->assertStringNotContainsString('Bajas sin partidos:', $html);
    }

    public function test_team_round_robin_excludes_team_withdrawn_before_fixture(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 4, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $this->markInactive($context, $group, $entries[2]);

        $context->generateTeamRoundRobin($group)->assertCreated();

        $teamTies = TeamTie::query()->where('group_id', $group->id)->get();
        $this->assertCount(3, $teamTies);
        $this->assertTrue($teamTies->every(
            fn (TeamTie $teamTie): bool => ! in_array($entries[2]->id, [(int) $teamTie->entry1_id, (int) $teamTie->entry2_id], true),
        ));
        $this->assertSame(15, TeamTieGame::query()->whereIn('team_tie_id', $teamTies->pluck('id'))->count());
    }

    public function test_team_generation_is_rejected_with_a_single_active_team(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $this->markInactive($context, $group, $entries[0]);

        $context->generateTeamRoundRobin($group)
            ->assertUnprocessable()
            ->assertJsonPath('errors.group.0', 'El grupo necesita al menos 2 equipos activos.');

        $this->assertSame(0, TeamTie::query()->where('group_id', $group->id)->count());
    }

    public function test_team_bracket_qualifies_single_active_team_without_team_ties(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 5, 4);
        $groupA = $context->createGroupWithEntries($competition, array_slice($entries, 0, 3), 'Grupo A');
        $groupB = $context->createGroupWithEntries($competition, array_slice($entries, 3, 2), 'Grupo B');
        $this->markInactive($context, $groupA, $entries[2]);
        $this->markInactive($context, $groupB, $entries[4]);
        $context->generateTeamRoundRobin($groupA)->assertCreated();

        $teamTie = TeamTie::query()->where('group_id', $groupA->id)->sole();
        $this->winTeamTie($context, $teamTie, $entries, (int) $teamTie->entry1_id);

        $context->createBracket($competition, qualifiedPerGroup: 1)->assertCreated();

        $bracketTie = TeamTie::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('bracket_id')
            ->sole();
        $this->assertEqualsCanonicalizing(
            [(int) $teamTie->entry1_id, $entries[3]->id],
            [(int) $bracketTie->entry1_id, (int) $bracketTie->entry2_id],
        );
    }

    /**
     * @return list<CompetitionEntry>
     */
    private function registerSinglesEntries(TournamentTestContext $context, Competition $competition, int $count): array
    {
        return array_map(
            fn ($player): CompetitionEntry => $context->registerPlayer($competition, $player),
            $context->createPlayers($count),
        );
    }

    private function markInactive(
        TournamentTestContext $context,
        Group $group,
        CompetitionEntry $entry,
        string $status = 'withdrawn',
    ): void {
        $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'competition_entry_id' => $entry->id,
            'status' => $status,
        ])->assertCreated();
    }

    /**
     * @return Collection<int, Game>
     */
    private function gamesInvolving(Group $group, CompetitionEntry $entry): Collection
    {
        return $group->games()
            ->where(fn ($query) => $query->where('entry1_id', $entry->id)->orWhere('entry2_id', $entry->id))
            ->get();
    }

    private function finishGroupGames(TournamentTestContext $context, Group $group): void
    {
        $group->games()
            ->where('status', '!=', GameStatus::Finished)
            ->get()
            ->each(fn (Game $game) => $context->finishGameByEntry($game, (int) $game->entry1_id));
    }

    /**
     * @return list<int>
     */
    private function bracketEntryIds(Competition $competition): array
    {
        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();

        return Game::query()
            ->where('bracket_id', $bracket->id)
            ->get()
            ->flatMap(fn (Game $game): array => [(int) $game->entry1_id, (int) $game->entry2_id])
            ->filter(fn (int $entryId): bool => $entryId > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function renderSheet(Group $group): string
    {
        $sheet = app(BuildPrintGroupSheetAction::class)($group->fresh());

        return view('pdf.groups.show', [
            'sheet' => $sheet,
            'title' => $sheet->group['name'],
        ])->render();
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
        foreach ([1, 2, 3] as $slotOrder) {
            $rubber = $teamTie->fresh()->teamTieGames()->where('slot_order', $slotOrder)->firstOrFail();
            $requiredPerSide = $rubber->modality === TeamTieModality::Doubles ? 2 : 1;
            $entry1 = collect($entries)->firstWhere('id', $teamTie->entry1_id);
            $entry2 = collect($entries)->firstWhere('id', $teamTie->entry2_id);

            $context->setTeamTieGameLineup($rubber, [
                'entry1_player_ids' => $this->memberPlayerIds($entry1, $requiredPerSide),
                'entry2_player_ids' => $this->memberPlayerIds($entry2, $requiredPerSide),
            ])->assertOk();
            $context->finishGameByEntryViaApi($rubber->game->fresh(), $winnerEntryId)->assertOk();
        }
    }

    /**
     * @return list<int>
     */
    private function memberPlayerIds(CompetitionEntry $entry, int $count): array
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
