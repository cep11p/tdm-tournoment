<?php

namespace Tests\Feature\Group;

use App\Actions\Group\ReconcileGroupRoundRobinGamesAction;
use App\Actions\GroupPlayer\RemoveEntryFromGroupAction;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Enums\TournamentStatus;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\GameSet;
use App\Models\Group;
use App\Models\GroupEntry;
use App\Models\TeamTie;
use App\Support\Competition\LateGroupMutationGuard;
use App\Support\Group\GroupRoundRobinSlotAllocator;
use App\Support\Group\GroupScheduleCompletion;
use App\Support\Group\GroupSheetNumbering;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class RemoveGroupEntryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_remove_without_games_leaves_remaining_entry_and_empty_fixture(): void
    {
        $context = $this->tournamentContext();
        $group = $this->createGroupWithEntries($context, 2);
        $entryIds = $this->entryIds($group);

        $this->assertSame(0, $group->games()->count());

        $context->removeEntryFromGroupViaApi($group, $entryIds[1])->assertNoContent();

        $this->assertSame(1, $group->groupEntries()->count());
        $this->assertSame($entryIds[0], (int) $group->groupEntries()->value('competition_entry_id'));
        $this->assertSame(0, $group->games()->count());
        $this->assertTrue(Group::query()->whereKey($group->id)->exists());
    }

    public function test_g4_to_g3_all_pending_removes_entry_and_obsolete_games(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $group = $setup['group'];
        $entryIds = $setup['entry_ids'];
        $keptBefore = [
            '1-3' => $this->snapshotGame($this->gameBetweenSheetNumbers($group, $entryIds, 1, 3)->load('sets')),
            '1-2' => $this->snapshotGame($this->gameBetweenSheetNumbers($group, $entryIds, 1, 2)->load('sets')),
            '2-3' => $this->snapshotGame($this->gameBetweenSheetNumbers($group, $entryIds, 2, 3)->load('sets')),
        ];
        $obsoleteIds = [
            $this->gameBetweenSheetNumbers($group, $entryIds, 2, 4)->id,
            $this->gameBetweenSheetNumbers($group, $entryIds, 3, 4)->id,
            $this->gameBetweenSheetNumbers($group, $entryIds, 1, 4)->id,
        ];
        $keptIds = array_values(array_map(fn (array $snapshot): int => $snapshot['id'], $keptBefore));
        sort($keptIds);

        $context->removeEntryFromGroupViaApi($group, $entryIds[3])->assertNoContent();

        $group = $group->fresh();
        $remainingEntryIds = array_slice($entryIds, 0, 3);

        $this->assertSame(3, $group->groupEntries()->count());
        $this->assertSame($remainingEntryIds, $this->entryIds($group));
        $this->assertSame(3, $group->games()->count());
        $this->assertSame($keptIds, $group->games()->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, $group->games()->whereIn('id', $obsoleteIds)->count());
        $this->assertUniqueGroupPairs($group, 3);

        $oneVsThree = $this->gameBetweenSheetNumbers($group, $remainingEntryIds, 1, 3);
        $oneVsTwo = $this->gameBetweenSheetNumbers($group, $remainingEntryIds, 1, 2);
        $twoVsThree = $this->gameBetweenSheetNumbers($group, $remainingEntryIds, 2, 3);

        $this->assertSame($keptBefore['1-3'], $this->snapshotGame($oneVsThree->fresh(['sets'])));
        $this->assertSame($keptBefore['1-2'], $this->snapshotGame($oneVsTwo->fresh(['sets'])));
        $this->assertSame($keptBefore['2-3'], $this->snapshotGame($twoVsThree->fresh(['sets'])));
        $this->assertSame(1, (int) $oneVsThree->group_round);
        $this->assertSame(1, (int) $oneVsThree->group_match);
        $this->assertSame(2, (int) $oneVsTwo->group_round);
        $this->assertSame(1, (int) $oneVsTwo->group_match);
        $this->assertSame(3, (int) $twoVsThree->group_round);
        $this->assertSame(2, (int) $twoVsThree->group_match);
        $this->assertTrue(GroupScheduleCompletion::hasCompleteGamesSchedule($group));
    }

    public function test_pending_games_of_remaining_entries_are_not_deleted(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $keptIds = [
            $this->gameBetweenSheetNumbers($setup['group'], $setup['entry_ids'], 1, 3)->id,
            $this->gameBetweenSheetNumbers($setup['group'], $setup['entry_ids'], 1, 2)->id,
            $this->gameBetweenSheetNumbers($setup['group'], $setup['entry_ids'], 2, 3)->id,
        ];

        $context->removeEntryFromGroupViaApi($setup['group'], $setup['entry_ids'][3])->assertNoContent();

        $this->assertSame(
            collect($keptIds)->sort()->values()->all(),
            $this->gameIds($setup['group'], $keptIds),
        );
        $this->assertSame(3, $setup['group']->games()->count());
    }

    public function test_finished_expected_game_does_not_block_remove(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $historical = $this->gameBetweenSheetNumbers($setup['group'], $setup['entry_ids'], 1, 3);
        $context->finishGame($historical, $historical->singlesPlayer1())->assertOk();
        $snapshot = $this->snapshotGame($historical->fresh(['sets']));

        $context->removeEntryFromGroupViaApi($setup['group'], $setup['entry_ids'][3])->assertNoContent();

        $this->assertSame(3, $setup['group']->games()->count());
        $this->assertSame($snapshot, $this->snapshotGame($historical->fresh(['sets'])));
        $this->assertSame(GameStatus::Finished, $historical->fresh()->status);
    }

    public function test_finished_game_of_removed_entry_blocks_and_rolls_back(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $obsolete = $this->gameBetweenSheetNumbers($setup['group'], $setup['entry_ids'], 1, 4);
        $context->finishGame($obsolete, $obsolete->singlesPlayer1())->assertOk();
        $originalIds = $setup['group']->games()->orderBy('id')->pluck('id')->all();
        $snapshots = $this->snapshotAllGames($setup['group']);

        $context->removeEntryFromGroupViaApi($setup['group'], $setup['entry_ids'][3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group'])
            ->assertJsonPath('errors.group.0', ReconcileGroupRoundRobinGamesAction::BLOCKED_MESSAGE);

        $this->assertSame(4, $setup['group']->groupEntries()->count());
        $this->assertTrue($this->groupHasEntry($setup['group'], $setup['entry_ids'][3]));
        $this->assertSame($originalIds, $setup['group']->games()->orderBy('id')->pluck('id')->all());
        $this->assertSame($snapshots, $this->snapshotAllGames($setup['group']));
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GROUP_PLAYER_REMOVED->value)->count());
    }

    public function test_in_progress_game_of_removed_entry_blocks_and_rolls_back(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context, setsToWin: 2);
        $obsolete = $this->gameBetweenSheetNumbers($setup['group'], $setup['entry_ids'], 1, 4);
        $context->recordSet($obsolete, setNumber: 1, player1Score: 11, player2Score: 5)->assertOk();
        $this->assertSame(GameStatus::InProgress, $obsolete->fresh()->status);
        $originalIds = $setup['group']->games()->orderBy('id')->pluck('id')->all();

        $context->removeEntryFromGroupViaApi($setup['group'], $setup['entry_ids'][3])
            ->assertUnprocessable()
            ->assertJsonPath('errors.group.0', ReconcileGroupRoundRobinGamesAction::BLOCKED_MESSAGE);

        $this->assertSame(4, $setup['group']->groupEntries()->count());
        $this->assertTrue($this->groupHasEntry($setup['group'], $setup['entry_ids'][3]));
        $this->assertSame($originalIds, $setup['group']->games()->orderBy('id')->pluck('id')->all());
        $this->assertSame(GameStatus::InProgress, $obsolete->fresh()->status);
    }

    public function test_obsolete_pending_game_with_forced_set_blocks_via_reconcile(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $obsolete = $this->gameBetweenSheetNumbers($setup['group'], $setup['entry_ids'], 1, 4);
        $set = GameSet::query()->create([
            'game_id' => $obsolete->id,
            'set_number' => 1,
            'player1_score' => 11,
            'player2_score' => 5,
        ]);

        $context->removeEntryFromGroupViaApi($setup['group'], $setup['entry_ids'][3])
            ->assertUnprocessable()
            ->assertJsonPath('errors.group.0', ReconcileGroupRoundRobinGamesAction::BLOCKED_MESSAGE);

        $this->assertTrue($this->groupHasEntry($setup['group'], $setup['entry_ids'][3]));
        $this->assertTrue(Game::query()->whereKey($obsolete->id)->exists());
        $this->assertTrue(GameSet::query()->whereKey($set->id)->exists());
        $this->assertSame(6, $setup['group']->games()->count());
    }

    public function test_g2_to_g1_deletes_the_only_pending_game(): void
    {
        $context = $this->tournamentContext();
        $group = $this->createGroupWithEntries($context, 2);
        $context->generateRoundRobin($group)->assertCreated();
        $this->assertSame(1, $group->games()->count());
        $remainingEntryId = $this->entryIds($group)[0];

        $context->removeEntryFromGroupViaApi($group, $this->entryIds($group)[1])->assertNoContent();

        $this->assertSame(1, $group->groupEntries()->count());
        $this->assertSame($remainingEntryId, (int) $group->groupEntries()->value('competition_entry_id'));
        $this->assertSame(0, $group->games()->count());
        $this->assertFalse(GroupScheduleCompletion::hasCompleteGamesSchedule($group->fresh()));
    }

    public function test_removing_last_entry_leaves_empty_group(): void
    {
        $context = $this->tournamentContext();
        $group = $this->createGroupWithEntries($context, 1);
        $entryId = $this->entryIds($group)[0];

        $context->removeEntryFromGroupViaApi($group, $entryId)->assertNoContent();

        $this->assertSame(0, $group->groupEntries()->count());
        $this->assertSame(0, $group->games()->count());
        $this->assertTrue(Group::query()->whereKey($group->id)->exists());
    }

    public function test_entry_not_in_group_returns_422_without_side_effects(): void
    {
        $context = $this->tournamentContext();
        $group = $this->createGroupWithEntries($context, 3);
        $context->generateRoundRobin($group)->assertCreated();
        [$unassignedPlayer] = $context->createPlayers(1);
        $unassigned = $context->registerPlayer($group->competition, $unassignedPlayer);
        $gamesBefore = $group->games()->count();

        $context->removeEntryFromGroupViaApi($group, $unassigned)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition_entry_id'])
            ->assertJsonPath('errors.competition_entry_id.0', RemoveEntryFromGroupAction::NOT_IN_GROUP_MESSAGE);

        $this->assertSame(3, $group->groupEntries()->count());
        $this->assertSame($gamesBefore, $group->games()->count());
    }

    public function test_entry_from_another_group_is_not_touched(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $groupA = $context->createGroupWithPlayers($competition, array_slice($players, 0, 2), 'Grupo A');
        $groupB = $context->createGroupWithPlayers($competition, array_slice($players, 2, 2), 'Grupo B');
        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();
        $entryB = $this->entryIds($groupB)[0];
        $gamesA = $groupA->games()->orderBy('id')->pluck('id')->all();
        $gamesB = $groupB->games()->orderBy('id')->pluck('id')->all();

        $context->removeEntryFromGroupViaApi($groupA, $entryB)
            ->assertUnprocessable()
            ->assertJsonPath('errors.competition_entry_id.0', RemoveEntryFromGroupAction::NOT_IN_GROUP_MESSAGE);

        $this->assertSame(2, $groupA->groupEntries()->count());
        $this->assertSame(2, $groupB->groupEntries()->count());
        $this->assertTrue($this->groupHasEntry($groupB, $entryB));
        $this->assertSame($gamesA, $groupA->games()->orderBy('id')->pluck('id')->all());
        $this->assertSame($gamesB, $groupB->games()->orderBy('id')->pluck('id')->all());
    }

    public function test_remove_is_blocked_when_bracket_exists(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);
        $context->createBracket($setup['competition'])->assertCreated();
        $entryId = $this->entryIds($setup['groupA'])[0];
        $entriesBefore = $setup['groupA']->groupEntries()->count();
        $gamesBefore = $setup['groupA']->games()->count();

        $context->removeEntryFromGroupViaApi($setup['groupA'], $entryId)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', LateGroupMutationGuard::LOCK_MESSAGE);

        $this->assertSame($entriesBefore, $setup['groupA']->groupEntries()->count());
        $this->assertSame($gamesBefore, $setup['groupA']->games()->count());
        $this->assertTrue($setup['competition']->brackets()->exists());
    }

    public function test_remove_is_blocked_when_tournament_is_finished(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $setup['group']->competition->tournament->update([
            'status' => TournamentStatus::Finished,
            'closed_at' => now(),
        ]);

        $context->removeEntryFromGroupViaApi($setup['group'], $setup['entry_ids'][3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament'])
            ->assertJsonPath('errors.tournament.0', TournamentLifecycleGuard::LOCK_MESSAGE);

        $this->assertSame(4, $setup['group']->groupEntries()->count());
        $this->assertSame(6, $setup['group']->games()->count());
    }

    public function test_doubles_remove_keeps_pair_entry_and_members(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(8);
        $entries = $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
            [$players[6], $players[7]],
        ]);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateRoundRobin($group)->assertCreated();
        $removed = $entries[3];
        $memberCount = CompetitionEntryMember::query()
            ->where('competition_entry_id', $removed->id)
            ->count();

        $context->removeEntryFromGroupViaApi($group, $removed)->assertNoContent();

        $this->assertSame(3, $group->groupEntries()->count());
        $this->assertSame(3, $group->games()->count());
        $this->assertTrue(CompetitionEntry::query()->whereKey($removed->id)->exists());
        $this->assertSame(
            $memberCount,
            CompetitionEntryMember::query()->where('competition_entry_id', $removed->id)->count(),
        );
        $this->assertGreaterThan(0, $memberCount);

        $gameEntryIds = $group->games()
            ->get()
            ->flatMap(fn (Game $game): array => [(int) $game->entry1_id, (int) $game->entry2_id])
            ->unique()
            ->all();
        $this->assertNotContains((int) $removed->id, $gameEntryIds);
    }

    public function test_team_groups_are_rejected_and_not_mutated(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();
        $tiesBefore = TeamTie::query()->where('group_id', $group->id)->count();

        $context->removeEntryFromGroupViaApi($group, $entries[0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group'])
            ->assertJsonPath(
                'errors.group.0',
                'La generación de partidos individuales no aplica a competencias por equipos.',
            );

        $this->assertSame(2, $group->groupEntries()->count());
        $this->assertSame($tiesBefore, TeamTie::query()->where('group_id', $group->id)->count());
        $this->assertSame(0, $group->games()->count());
    }

    public function test_print_g3_works_immediately_after_removing_fourth_entry(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);

        $context->removeEntryFromGroupViaApi($setup['group'], $setup['entry_ids'][3])->assertNoContent();

        $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->assertJsonCount(3, 'data.matches')
            ->assertJsonPath('data.sheet_kind', 'g3');
    }

    public function test_standings_after_g4_to_g3_include_only_remaining_entries(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $removedEntryId = $setup['entry_ids'][3];
        $keptEntryIds = collect(array_slice($setup['entry_ids'], 0, 3))->sort()->values()->all();

        $context->removeEntryFromGroupViaApi($setup['group'], $removedEntryId)->assertNoContent();

        $standings = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/standings"))
            ->assertOk()
            ->json('data');

        $standingIds = collect($standings)
            ->pluck('competition_entry_id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $this->assertSame($keptEntryIds, $standingIds);
        $this->assertNotContains($removedEntryId, $standingIds);

        $gameEntryIds = $setup['group']->games()
            ->get()
            ->flatMap(fn (Game $game): array => [(int) $game->entry1_id, (int) $game->entry2_id])
            ->unique()
            ->all();
        $this->assertNotContains($removedEntryId, $gameEntryIds);
    }

    public function test_status_after_g4_to_g3_stays_group_stage_in_progress(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);

        $context->removeEntryFromGroupViaApi($setup['group'], $setup['entry_ids'][3])->assertNoContent();

        $this->getJson($context->apiUrl("competitions/{$setup['group']->competition_id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'group_stage_in_progress');
    }

    public function test_status_after_g2_to_g1_is_group_stage_pending(): void
    {
        $context = $this->tournamentContext();
        $group = $this->createGroupWithEntries($context, 2);
        $context->generateRoundRobin($group)->assertCreated();

        $context->removeEntryFromGroupViaApi($group, $this->entryIds($group)[1])->assertNoContent();

        $this->getJson($context->apiUrl("competitions/{$group->competition_id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'group_stage_pending');
    }

    public function test_successful_remove_audits_one_player_removed_event(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $removedEntryId = $setup['entry_ids'][3];
        Activity::query()->delete();

        $context->removeEntryFromGroupViaApi($setup['group'], $removedEntryId)->assertNoContent();

        $activity = Activity::query()
            ->where('description', AuditAction::GROUP_PLAYER_REMOVED->value)
            ->sole();

        $this->assertSame('groups', $activity->log_name);
        $this->assertSame(Group::class, $activity->subject_type);
        $this->assertSame($setup['group']->id, $activity->subject_id);
        $this->assertSame($setup['group']->id, data_get($activity->properties, 'context.group_id'));
        $this->assertSame($setup['group']->competition_id, data_get($activity->properties, 'context.competition_id'));
        $this->assertSame($removedEntryId, data_get($activity->properties, 'summary.competition_entry_id'));
        $this->assertSame($setup['group']->name, data_get($activity->properties, 'summary.group_name'));
        $this->assertNotEmpty(data_get($activity->properties, 'summary.display_name'));
        $this->assertSame(1, Activity::query()->count());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GAME_DELETED->value)->count());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GROUPS_ROUND_ROBIN_GENERATED->value)->count());
    }

    public function test_failed_remove_does_not_emit_player_removed_audit(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $obsolete = $this->gameBetweenSheetNumbers($setup['group'], $setup['entry_ids'], 1, 4);
        $context->finishGame($obsolete, $obsolete->singlesPlayer1())->assertOk();
        Activity::query()->delete();

        $context->removeEntryFromGroupViaApi($setup['group'], $setup['entry_ids'][3])
            ->assertUnprocessable();

        $this->assertSame(0, Activity::query()->where('description', AuditAction::GROUP_PLAYER_REMOVED->value)->count());
    }

    public function test_failure_during_reconcile_rolls_back_group_entry_games_and_audit(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $group = $setup['group'];
        $entryIds = $setup['entry_ids'];
        $missing = $this->gameBetweenSheetNumbers($group, $entryIds, 1, 2);
        $obsoleteIds = [
            $this->gameBetweenSheetNumbers($group, $entryIds, 2, 4)->id,
            $this->gameBetweenSheetNumbers($group, $entryIds, 3, 4)->id,
            $this->gameBetweenSheetNumbers($group, $entryIds, 1, 4)->id,
        ];
        $missing->delete();
        $idsAfterHole = $group->games()->orderBy('id')->pluck('id')->all();
        Activity::query()->delete();

        Game::creating(function (): void {
            throw new RuntimeException('forced missing-pair failure');
        });

        try {
            app(RemoveEntryFromGroupAction::class)($group->fresh(), $entryIds[3]);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced missing-pair failure', $exception->getMessage());
        } catch (ValidationException $exception) {
            $this->fail('Expected RuntimeException, got ValidationException: '.json_encode($exception->errors()));
        }

        $this->assertTrue($this->groupHasEntry($group, $entryIds[3]));
        $this->assertSame(4, $group->groupEntries()->count());
        $this->assertSame($idsAfterHole, $group->games()->orderBy('id')->pluck('id')->all());
        $this->assertSame($obsoleteIds, $this->gameIds($group, $obsoleteIds));
        $this->assertFalse(Game::query()->whereKey($missing->id)->exists());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GROUP_PLAYER_REMOVED->value)->count());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
    }

    public function test_competition_entry_and_members_are_not_deleted(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $removedEntryId = $setup['entry_ids'][3];
        $memberCount = CompetitionEntryMember::query()
            ->where('competition_entry_id', $removedEntryId)
            ->count();

        $context->removeEntryFromGroupViaApi($setup['group'], $removedEntryId)->assertNoContent();

        $this->assertTrue(CompetitionEntry::query()->whereKey($removedEntryId)->exists());
        $this->assertSame(
            $memberCount,
            CompetitionEntryMember::query()->where('competition_entry_id', $removedEntryId)->count(),
        );
        $this->assertFalse($this->groupHasEntry($setup['group'], $removedEntryId));
    }

    public function test_removed_entry_can_be_assigned_to_another_group(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $removedEntryId = $setup['entry_ids'][3];
        $removedEntry = CompetitionEntry::query()->findOrFail($removedEntryId);
        $other = $context->createGroup($setup['group']->competition, 'Grupo B');

        $context->removeEntryFromGroupViaApi($setup['group'], $removedEntryId)->assertNoContent();
        $context->assignEntryToGroupViaApi($other, $removedEntry)->assertCreated();

        $this->assertFalse($this->groupHasEntry($setup['group'], $removedEntryId));
        $this->assertTrue($this->groupHasEntry($other, $removedEntryId));
        $this->assertSame(1, $other->groupEntries()->count());
    }

    public function test_scorekeeper_cannot_remove_group_entry(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);

        $context->removeEntryFromGroupViaApi($setup['group'], $setup['entry_ids'][3], ['scorekeeper'])
            ->assertForbidden();

        $this->assertSame(4, $setup['group']->groupEntries()->count());
    }

    /**
     * @return array{
     *     group: Group,
     *     entry_ids: list<int>
     * }
     */
    private function createNativeG4(TournamentTestContext $context, int $setsToWin = 1): array
    {
        $group = $this->createGroupWithEntries($context, 4, $setsToWin);
        $context->generateRoundRobin($group)->assertCreated();

        return [
            'group' => $group->fresh(),
            'entry_ids' => $this->entryIds($group),
        ];
    }

    private function createGroupWithEntries(TournamentTestContext $context, int $count, int $setsToWin = 1): Group
    {
        $competition = $context->createCompetition($setsToWin);
        $players = $context->createPlayers($count);
        $context->registerPlayers($competition, $players);

        return $context->createGroupWithPlayers($competition, $players);
    }

    /**
     * @return list<int>
     */
    private function entryIds(Group $group): array
    {
        return $group->groupEntries()
            ->orderBy('competition_entry_id')
            ->pluck('competition_entry_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function groupHasEntry(Group $group, int $entryId): bool
    {
        return GroupEntry::query()
            ->where('group_id', $group->id)
            ->where('competition_entry_id', $entryId)
            ->exists();
    }

    /**
     * @param  list<int>  $entryIds
     */
    private function gameBetweenSheetNumbers(
        Group $group,
        array $entryIds,
        int $sheetLeft,
        int $sheetRight,
    ): Game {
        $numbers = GroupSheetNumbering::forCompetitionEntryIds($entryIds);
        $idByNumber = array_flip($numbers);

        return $this->tournamentContext()->findGameBetweenEntries(
            $group->games()->get(),
            (int) $idByNumber[$sheetLeft],
            (int) $idByNumber[$sheetRight],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotGame(Game $game): array
    {
        return [
            'id' => (int) $game->id,
            'status' => $game->status instanceof GameStatus ? $game->status->value : (string) $game->status,
            'winner_entry_id' => $game->winner_entry_id,
            'entry1_id' => (int) $game->entry1_id,
            'entry2_id' => (int) $game->entry2_id,
            'group_round' => $game->group_round,
            'group_match' => $game->group_match,
            'best_of' => $game->best_of,
            'sets_to_win' => $game->sets_to_win,
            'finished_at' => $game->finished_at?->toJSON(),
            'created_at' => $game->created_at?->toJSON(),
            'updated_at' => $game->updated_at?->toJSON(),
            'sets' => $game->sets
                ->sortBy('id')
                ->values()
                ->map(fn (GameSet $set): array => [
                    'id' => (int) $set->id,
                    'set_number' => (int) $set->set_number,
                    'player1_score' => (int) $set->player1_score,
                    'player2_score' => (int) $set->player2_score,
                ])
                ->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshotAllGames(Group $group): array
    {
        return $group->games()->orderBy('id')->get()->map(
            fn (Game $game): array => $this->snapshotGame($game->fresh(['sets'])),
        )->all();
    }

    /**
     * @param  list<int>  $expectedIds
     * @return list<int>
     */
    private function gameIds(Group $group, array $expectedIds): array
    {
        return $group->games()
            ->whereIn('id', $expectedIds)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private function assertUniqueGroupPairs(Group $group, int $expectedCount): void
    {
        $keys = $group->games()
            ->get()
            ->map(fn (Game $game): string => GroupRoundRobinSlotAllocator::pairKey(
                (int) $game->entry1_id,
                (int) $game->entry2_id,
            ));

        $this->assertCount($expectedCount, $keys);
        $this->assertSame($keys->count(), $keys->unique()->count());
    }
}
