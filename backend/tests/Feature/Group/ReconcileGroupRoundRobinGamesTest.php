<?php

namespace Tests\Feature\Group;

use App\Actions\Group\ReconcileGroupRoundRobinGamesAction;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameSet;
use App\Models\Group;
use App\Models\GroupEntry;
use App\Models\TeamTie;
use App\Support\Group\GroupRoundRobinSlotAllocator;
use App\Support\Group\GroupScheduleCompletion;
use App\Support\Group\GroupSheetNumbering;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class ReconcileGroupRoundRobinGamesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_correct_fixture_is_left_unchanged(): void
    {
        $context = $this->tournamentContext();
        $group = $this->createGroupWithEntries($context, 3);
        $context->generateRoundRobin($group)->assertCreated();
        $originalIds = $group->games()->orderBy('id')->pluck('id')->all();
        $snapshots = $group->games()->orderBy('id')->get()->map(
            fn (Game $game): array => $this->snapshotGame($game->fresh(['sets'])),
        )->all();

        $remaining = app(ReconcileGroupRoundRobinGamesAction::class)($group->fresh());

        $this->assertSame($originalIds, $remaining->pluck('id')->all());
        $this->assertSame($originalIds, $group->games()->orderBy('id')->pluck('id')->all());
        $this->assertSame(
            $snapshots,
            $group->games()->orderBy('id')->get()->map(
                fn (Game $game): array => $this->snapshotGame($game->fresh(['sets'])),
            )->all(),
        );
        $this->assertUniqueGroupPairs($group, 3);
    }

    public function test_g4_to_g3_all_pending_deletes_exactly_three_obsolete_games_and_preserves_ids_and_slots(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $entryIds = $setup['entry_ids'];
        $group = $setup['group'];

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

        $this->removeEntryFromGroup($group, $entryIds[3]);

        app(ReconcileGroupRoundRobinGamesAction::class)($group->fresh());

        $group = $group->fresh();
        $keptIds = array_values(array_map(fn (array $snapshot): int => $snapshot['id'], $keptBefore));
        sort($keptIds);
        $remainingIds = $group->games()->orderBy('id')->pluck('id')->all();

        $this->assertSame(3, $group->groupEntries()->count());
        $this->assertSame(3, $group->games()->count());
        $this->assertSame($keptIds, $this->gameIds($group, $keptIds));
        $this->assertSame($keptIds, $remainingIds);
        $this->assertSame(0, $group->games()->whereIn('id', $obsoleteIds)->count());
        $this->assertUniqueGroupPairs($group, 3);

        $remainingEntryIds = array_slice($entryIds, 0, 3);
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

        $remainingPairKeys = $group->games()
            ->get()
            ->map(fn (Game $game): string => GroupRoundRobinSlotAllocator::pairKey(
                (int) $game->entry1_id,
                (int) $game->entry2_id,
            ))
            ->sort()
            ->values()
            ->all();
        $expectedPairKeys = [
            GroupRoundRobinSlotAllocator::pairKey($remainingEntryIds[0], $remainingEntryIds[2]),
            GroupRoundRobinSlotAllocator::pairKey($remainingEntryIds[0], $remainingEntryIds[1]),
            GroupRoundRobinSlotAllocator::pairKey($remainingEntryIds[1], $remainingEntryIds[2]),
        ];
        sort($expectedPairKeys);
        $this->assertSame($expectedPairKeys, $remainingPairKeys);
    }

    public function test_pending_games_of_another_group_are_left_intact(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $other = $this->createGroupWithEntries($context, 3);
        $context->generateRoundRobin($other)->assertCreated();
        $otherIds = $other->games()->orderBy('id')->pluck('id')->all();
        $otherSnapshots = $other->games()->orderBy('id')->get()->map(
            fn (Game $game): array => $this->snapshotGame($game->fresh(['sets'])),
        )->all();

        $this->removeEntryFromGroup($setup['group'], $setup['entry_ids'][3]);
        app(ReconcileGroupRoundRobinGamesAction::class)($setup['group']->fresh());

        $this->assertSame($otherIds, $other->games()->orderBy('id')->pluck('id')->all());
        $this->assertSame(
            $otherSnapshots,
            $other->games()->orderBy('id')->get()->map(
                fn (Game $game): array => $this->snapshotGame($game->fresh(['sets'])),
            )->all(),
        );
        $this->assertSame(3, $setup['group']->games()->count());
    }

    public function test_missing_expected_pair_is_the_only_game_created(): void
    {
        $context = $this->tournamentContext();
        $group = $this->createGroupWithEntries($context, 3);
        $context->generateRoundRobin($group)->assertCreated();
        $entryIds = $this->entryIds($group);
        $missing = $this->gameBetweenSheetNumbers($group, $entryIds, 2, 3);
        $keptIds = $group->games()->where('id', '!=', $missing->id)->orderBy('id')->pluck('id')->all();
        $missing->delete();

        $this->assertSame(2, $group->games()->count());

        app(ReconcileGroupRoundRobinGamesAction::class)($group->fresh());

        $group = $group->fresh();
        $this->assertSame(3, $group->games()->count());
        $this->assertSame($keptIds, $this->gameIds($group, $keptIds));
        $this->assertUniqueGroupPairs($group, 3);

        $recreated = $this->gameBetweenSheetNumbers($group, $entryIds, 2, 3);
        $this->assertNotContains($recreated->id, $keptIds);
        $this->assertSame(GameStatus::Pending, $recreated->status);
    }

    public function test_obsolete_finished_game_blocks_and_rolls_back(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $group = $setup['group'];
        $entryIds = $setup['entry_ids'];
        $obsolete = $this->gameBetweenSheetNumbers($group, $entryIds, 1, 4);
        $context->finishGame($obsolete, $obsolete->singlesPlayer1())->assertOk();
        $originalIds = $group->games()->orderBy('id')->pluck('id')->all();
        $snapshots = $group->games()->orderBy('id')->get()->map(
            fn (Game $game): array => $this->snapshotGame($game->fresh(['sets'])),
        )->all();

        $this->removeEntryFromGroup($group, $entryIds[3]);

        try {
            app(ReconcileGroupRoundRobinGamesAction::class)($group->fresh());
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ReconcileGroupRoundRobinGamesAction::BLOCKED_MESSAGE,
                $exception->errors()['group'][0],
            );
        }

        $this->assertSame($originalIds, $group->games()->orderBy('id')->pluck('id')->all());
        $this->assertSame(
            $snapshots,
            $group->games()->orderBy('id')->get()->map(
                fn (Game $game): array => $this->snapshotGame($game->fresh(['sets'])),
            )->all(),
        );
        $this->assertSame(3, $group->groupEntries()->count());
    }

    public function test_obsolete_in_progress_game_blocks_and_rolls_back(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context, setsToWin: 2);
        $group = $setup['group'];
        $entryIds = $setup['entry_ids'];
        $obsolete = $this->gameBetweenSheetNumbers($group, $entryIds, 1, 4);
        $context->recordSet($obsolete, setNumber: 1, player1Score: 11, player2Score: 5)->assertOk();
        $this->assertSame(GameStatus::InProgress, $obsolete->fresh()->status);
        $originalIds = $group->games()->orderBy('id')->pluck('id')->all();
        $snapshots = $group->games()->orderBy('id')->get()->map(
            fn (Game $game): array => $this->snapshotGame($game->fresh(['sets'])),
        )->all();

        $this->removeEntryFromGroup($group, $entryIds[3]);

        try {
            app(ReconcileGroupRoundRobinGamesAction::class)($group->fresh());
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ReconcileGroupRoundRobinGamesAction::BLOCKED_MESSAGE,
                $exception->errors()['group'][0],
            );
        }

        $this->assertSame($originalIds, $group->games()->orderBy('id')->pluck('id')->all());
        $this->assertSame(
            $snapshots,
            $group->games()->orderBy('id')->get()->map(
                fn (Game $game): array => $this->snapshotGame($game->fresh(['sets'])),
            )->all(),
        );
    }

    public function test_finished_expected_game_is_preserved_and_does_not_block(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $group = $setup['group'];
        $entryIds = $setup['entry_ids'];
        $historical = $this->gameBetweenSheetNumbers($group, $entryIds, 1, 3);
        $context->finishGame($historical, $historical->singlesPlayer1())->assertOk();
        $snapshot = $this->snapshotGame($historical->fresh(['sets']));

        $this->removeEntryFromGroup($group, $entryIds[3]);
        app(ReconcileGroupRoundRobinGamesAction::class)($group->fresh());

        $this->assertSame(3, $group->games()->count());
        $this->assertSame($snapshot, $this->snapshotGame($historical->fresh(['sets'])));
        $this->assertSame(GameStatus::Finished, $historical->fresh()->status);
    }

    public function test_sets_of_expected_games_keep_ids_and_scores(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $group = $setup['group'];
        $entryIds = $setup['entry_ids'];
        $historical = $this->gameBetweenSheetNumbers($group, $entryIds, 1, 2);
        $context->finishGame($historical, $historical->singlesPlayer1())->assertOk();
        $setRows = GameSet::query()
            ->where('game_id', $historical->id)
            ->orderBy('id')
            ->get()
            ->map(fn (GameSet $set): array => $set->only([
                'id',
                'game_id',
                'set_number',
                'player1_score',
                'player2_score',
            ]))
            ->all();

        $this->removeEntryFromGroup($group, $entryIds[3]);
        app(ReconcileGroupRoundRobinGamesAction::class)($group->fresh());

        $this->assertSame(
            $setRows,
            GameSet::query()
                ->where('game_id', $historical->id)
                ->orderBy('id')
                ->get()
                ->map(fn (GameSet $set): array => $set->only([
                    'id',
                    'game_id',
                    'set_number',
                    'player1_score',
                    'player2_score',
                ]))
                ->all(),
        );
    }

    public function test_obsolete_pending_game_with_forced_set_is_blocked_and_not_deleted(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $group = $setup['group'];
        $entryIds = $setup['entry_ids'];
        $obsolete = $this->gameBetweenSheetNumbers($group, $entryIds, 1, 4);
        $set = GameSet::query()->create([
            'game_id' => $obsolete->id,
            'set_number' => 1,
            'player1_score' => 11,
            'player2_score' => 5,
        ]);
        $this->assertSame(GameStatus::Pending, $obsolete->fresh()->status);

        $this->removeEntryFromGroup($group, $entryIds[3]);

        try {
            app(ReconcileGroupRoundRobinGamesAction::class)($group->fresh());
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ReconcileGroupRoundRobinGamesAction::BLOCKED_MESSAGE,
                $exception->errors()['group'][0],
            );
        }

        $this->assertTrue(Game::query()->whereKey($obsolete->id)->exists());
        $this->assertTrue(GameSet::query()->whereKey($set->id)->exists());
        $this->assertSame(6, $group->games()->count());
        $this->assertSame(GameStatus::Pending, $obsolete->fresh()->status);
    }

    public function test_doubles_reconcile_uses_competition_entry_identity(): void
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
        $this->assertSame(6, $group->games()->count());

        $removedEntryId = (int) $entries[3]->id;
        $keptEntryIds = collect($entries)
            ->take(3)
            ->map(fn ($entry): int => (int) $entry->id)
            ->sort()
            ->values()
            ->all();
        $this->removeEntryFromGroup($group, $removedEntryId);

        app(ReconcileGroupRoundRobinGamesAction::class)($group->fresh());

        $this->assertSame(3, $group->games()->count());
        $this->assertUniqueGroupPairs($group, 3);

        $gameEntryIds = $group->games()
            ->get()
            ->flatMap(fn (Game $game): array => [(int) $game->entry1_id, (int) $game->entry2_id])
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame($keptEntryIds, $gameEntryIds);
        $this->assertNotContains($removedEntryId, $gameEntryIds);
    }

    public function test_second_reconcile_is_idempotent(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $this->removeEntryFromGroup($setup['group'], $setup['entry_ids'][3]);
        app(ReconcileGroupRoundRobinGamesAction::class)($setup['group']->fresh());

        $idsAfterFirst = $setup['group']->games()->orderBy('id')->pluck('id')->all();
        $snapshots = $setup['group']->games()->orderBy('id')->get()->map(
            fn (Game $game): array => $this->snapshotGame($game->fresh(['sets'])),
        )->all();

        app(ReconcileGroupRoundRobinGamesAction::class)($setup['group']->fresh());

        $this->assertSame($idsAfterFirst, $setup['group']->games()->orderBy('id')->pluck('id')->all());
        $this->assertSame(
            $snapshots,
            $setup['group']->games()->orderBy('id')->get()->map(
                fn (Game $game): array => $this->snapshotGame($game->fresh(['sets'])),
            )->all(),
        );
    }

    public function test_print_g3_resolves_after_g4_to_g3_reconcile(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $this->removeEntryFromGroup($setup['group'], $setup['entry_ids'][3]);
        app(ReconcileGroupRoundRobinGamesAction::class)($setup['group']->fresh());

        $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->assertJsonCount(3, 'data.matches')
            ->assertJsonPath('data.sheet_kind', 'g3');
    }

    public function test_group_schedule_completion_matches_g3_after_reconcile(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $this->removeEntryFromGroup($setup['group'], $setup['entry_ids'][3]);
        app(ReconcileGroupRoundRobinGamesAction::class)($setup['group']->fresh());

        $group = $setup['group']->fresh();
        $this->assertSame(3, $group->groupEntries()->count());
        $this->assertSame(3, $group->games()->count());
        $this->assertSame(3, GroupScheduleCompletion::expectedPairCount(3));
        $this->assertTrue(GroupScheduleCompletion::hasCompleteGamesSchedule($group));
        $this->assertTrue(GroupScheduleCompletion::hasOpenGames($group));
    }

    public function test_standings_include_only_current_entries_and_no_games_against_removed_entry(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $removedEntryId = $setup['entry_ids'][3];
        $keptEntryIds = array_slice($setup['entry_ids'], 0, 3);

        $this->removeEntryFromGroup($setup['group'], $removedEntryId);
        app(ReconcileGroupRoundRobinGamesAction::class)($setup['group']->fresh());

        $standings = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/standings"))
            ->assertOk()
            ->json('data');

        $standingEntryIds = collect($standings)
            ->pluck('competition_entry_id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        $expectedStandingIds = collect($keptEntryIds)->sort()->values()->all();

        $this->assertSame($expectedStandingIds, $standingEntryIds);
        $this->assertNotContains($removedEntryId, $standingEntryIds);

        $gameEntryIds = $setup['group']->games()
            ->get()
            ->flatMap(fn (Game $game): array => [(int) $game->entry1_id, (int) $game->entry2_id])
            ->unique()
            ->all();
        $this->assertNotContains($removedEntryId, $gameEntryIds);
    }

    public function test_pending_games_keep_competition_in_group_stage_in_progress(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $this->removeEntryFromGroup($setup['group'], $setup['entry_ids'][3]);
        app(ReconcileGroupRoundRobinGamesAction::class)($setup['group']->fresh());

        $this->getJson($context->apiUrl("competitions/{$setup['group']->competition_id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'group_stage_in_progress');
    }

    public function test_team_groups_are_rejected_and_not_mutated(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();
        $tiesBefore = TeamTie::query()->where('group_id', $group->id)->count();
        $gamesBefore = $group->games()->count();

        try {
            app(ReconcileGroupRoundRobinGamesAction::class)($group->fresh());
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'La generación de partidos individuales no aplica a competencias por equipos.',
                $exception->errors()['group'][0],
            );
        }

        $this->assertSame($tiesBefore, TeamTie::query()->where('group_id', $group->id)->count());
        $this->assertSame($gamesBefore, $group->games()->count());
        $this->assertSame(0, $group->games()->count());
        $this->assertSame(2, $group->groupEntries()->count());
    }

    public function test_failure_while_creating_missing_pair_rolls_back_deleted_obsolete_games(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $group = $setup['group'];
        $entryIds = $setup['entry_ids'];
        $originalIds = $group->games()->orderBy('id')->pluck('id')->all();
        $missing = $this->gameBetweenSheetNumbers($group, $entryIds, 1, 2);
        $obsoleteIds = [
            $this->gameBetweenSheetNumbers($group, $entryIds, 2, 4)->id,
            $this->gameBetweenSheetNumbers($group, $entryIds, 3, 4)->id,
            $this->gameBetweenSheetNumbers($group, $entryIds, 1, 4)->id,
        ];
        $missing->delete();
        $idsAfterHole = $group->games()->orderBy('id')->pluck('id')->all();
        $this->assertNotContains($missing->id, $idsAfterHole);

        $this->removeEntryFromGroup($group, $entryIds[3]);

        Game::creating(function (): void {
            throw new RuntimeException('forced missing-pair failure');
        });

        try {
            app(ReconcileGroupRoundRobinGamesAction::class)($group->fresh());
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced missing-pair failure', $exception->getMessage());
        }

        $restoredIds = $group->games()->orderBy('id')->pluck('id')->all();
        $this->assertSame($idsAfterHole, $restoredIds);
        $this->assertSame($obsoleteIds, $this->gameIds($group, $obsoleteIds));
        $this->assertFalse(Game::query()->whereKey($missing->id)->exists());
        $this->assertNotSame($originalIds, $restoredIds);
        $this->assertSame('sqlite', DB::connection()->getDriverName());
    }

    public function test_reconcile_does_not_emit_functional_audit_events(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createNativeG4($context);
        $this->removeEntryFromGroup($setup['group'], $setup['entry_ids'][3]);
        Activity::query()->delete();

        app(ReconcileGroupRoundRobinGamesAction::class)($setup['group']->fresh());

        $this->assertSame(0, Activity::query()->count());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GAME_CREATED->value)->count());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GAME_DELETED->value)->count());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GROUPS_ROUND_ROBIN_GENERATED->value)->count());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GROUP_PLAYER_ASSIGNED->value)->count());
    }

    public function test_zero_or_one_entries_delete_obsolete_pending_games_and_create_none(): void
    {
        $context = $this->tournamentContext();
        $group = $this->createGroupWithEntries($context, 2);
        $context->generateRoundRobin($group)->assertCreated();
        $this->assertSame(1, $group->games()->count());
        $remainingEntryId = $this->entryIds($group)[0];
        $this->removeEntryFromGroup($group, $this->entryIds($group)[1]);

        app(ReconcileGroupRoundRobinGamesAction::class)($group->fresh());

        $this->assertSame(1, $group->groupEntries()->count());
        $this->assertSame($remainingEntryId, (int) $group->groupEntries()->value('competition_entry_id'));
        $this->assertSame(0, $group->games()->count());
        $this->assertFalse(GroupScheduleCompletion::hasCompleteGamesSchedule($group->fresh()));
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

    private function removeEntryFromGroup(Group $group, int $entryId): void
    {
        GroupEntry::query()
            ->where('group_id', $group->id)
            ->where('competition_entry_id', $entryId)
            ->delete();
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
