<?php

namespace Tests\Feature\Group;

use App\Actions\Group\ReconcileGroupRoundRobinGamesAction;
use App\Actions\GroupPlayer\MoveCompetitionEntryBetweenGroupsAction;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Enums\GroupPlayerStatus;
use App\Enums\GroupPlayerStatusReason;
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

class MoveGroupEntryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_move_without_games_updates_group_id_and_creates_no_fixture(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $entry = $context->registerPlayer($competition, $player);
        $source = $context->createGroupWithPlayers($competition, [$player], 'Grupo A');
        $target = $context->createGroup($competition, 'Grupo B');
        $groupEntryId = (int) $source->groupEntries()->value('id');

        $context->moveEntryBetweenGroupsViaApi($source, $entry, $target)
            ->assertOk()
            ->assertJsonPath('data.id', $groupEntryId)
            ->assertJsonPath('data.group_id', $target->id)
            ->assertJsonPath('data.competition_entry_id', $entry->id);

        $this->assertSame(0, $source->groupEntries()->count());
        $this->assertSame(1, $target->groupEntries()->count());
        $this->assertSame($groupEntryId, (int) $target->groupEntries()->value('id'));
        $this->assertSame(0, $source->games()->count());
        $this->assertSame(0, $target->games()->count());
        $this->assertTrue(Group::query()->whereKey($source->id)->exists());
    }

    public function test_move_g4_to_g3_all_pending_reconciles_both_groups(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);
        $source = $setup['source'];
        $target = $setup['target'];
        $sourceIds = $setup['source_entry_ids'];
        $targetIds = $setup['target_entry_ids'];
        $movedId = $sourceIds[3];

        $keptSource = [
            '1-3' => $this->snapshotGame($this->gameBetweenSheetNumbers($source, $sourceIds, 1, 3)->load('sets')),
            '1-2' => $this->snapshotGame($this->gameBetweenSheetNumbers($source, $sourceIds, 1, 2)->load('sets')),
            '2-3' => $this->snapshotGame($this->gameBetweenSheetNumbers($source, $sourceIds, 2, 3)->load('sets')),
        ];
        $obsoleteSourceIds = [
            $this->gameBetweenSheetNumbers($source, $sourceIds, 2, 4)->id,
            $this->gameBetweenSheetNumbers($source, $sourceIds, 3, 4)->id,
            $this->gameBetweenSheetNumbers($source, $sourceIds, 1, 4)->id,
        ];
        $keptSourceIds = array_values(array_map(fn (array $snapshot): int => $snapshot['id'], $keptSource));
        sort($keptSourceIds);
        $targetGameIds = $target->games()->orderBy('id')->pluck('id')->all();
        $targetSnapshots = $this->snapshotAllGames($target);

        $context->moveEntryBetweenGroupsViaApi($source, $movedId, $target)->assertOk();

        $source = $source->fresh();
        $target = $target->fresh();
        $remainingSourceIds = array_slice($sourceIds, 0, 3);

        $this->assertSame(3, $source->groupEntries()->count());
        $this->assertSame(4, $target->groupEntries()->count());
        $this->assertSame($remainingSourceIds, $this->entryIds($source));
        $this->assertContains($movedId, $this->entryIds($target));
        $this->assertNotContains($movedId, $this->entryIds($source));

        $this->assertSame(3, $source->games()->count());
        $this->assertSame(6, $target->games()->count());
        $this->assertSame($keptSourceIds, $source->games()->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, $source->games()->whereIn('id', $obsoleteSourceIds)->count());
        $this->assertSame($targetGameIds, $this->gameIds($target, $targetGameIds));
        $this->assertSame($targetSnapshots, $this->snapshotAllGamesByIds($target, $targetGameIds));

        $oneVsThree = $this->gameBetweenSheetNumbers($source, $remainingSourceIds, 1, 3);
        $oneVsTwo = $this->gameBetweenSheetNumbers($source, $remainingSourceIds, 1, 2);
        $twoVsThree = $this->gameBetweenSheetNumbers($source, $remainingSourceIds, 2, 3);
        $this->assertSame($keptSource['1-3'], $this->snapshotGame($oneVsThree->fresh(['sets'])));
        $this->assertSame($keptSource['1-2'], $this->snapshotGame($oneVsTwo->fresh(['sets'])));
        $this->assertSame($keptSource['2-3'], $this->snapshotGame($twoVsThree->fresh(['sets'])));
        $this->assertSame(1, (int) $oneVsThree->group_round);
        $this->assertSame(1, (int) $oneVsThree->group_match);
        $this->assertSame(2, (int) $oneVsTwo->group_round);
        $this->assertSame(1, (int) $oneVsTwo->group_match);
        $this->assertSame(3, (int) $twoVsThree->group_round);
        $this->assertSame(2, (int) $twoVsThree->group_match);

        $created = $target->games()->whereNotIn('id', $targetGameIds)->get();
        $this->assertCount(3, $created);
        foreach ($created as $game) {
            $sides = [(int) $game->entry1_id, (int) $game->entry2_id];
            $this->assertContains($movedId, $sides);
            $other = $sides[0] === $movedId ? $sides[1] : $sides[0];
            $this->assertContains($other, $targetIds);
        }

        $this->assertTrue(GroupScheduleCompletion::hasCompleteGamesSchedule($source));
        $this->assertTrue(GroupScheduleCompletion::hasCompleteGamesSchedule($target));
        $this->assertUniqueGroupPairs($source, 3);
        $this->assertUniqueGroupPairs($target, 6);
    }

    public function test_finished_expected_game_in_source_does_not_block_move(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);
        $historical = $this->gameBetweenSheetNumbers($setup['source'], $setup['source_entry_ids'], 1, 2);
        $context->finishGame($historical, $historical->singlesPlayer1())->assertOk();
        $snapshot = $this->snapshotGame($historical->fresh(['sets']));

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $setup['source_entry_ids'][3], $setup['target'])
            ->assertOk();

        $this->assertSame($snapshot, $this->snapshotGame($historical->fresh(['sets'])));
        $this->assertSame(3, $setup['source']->games()->count());
        $this->assertFalse($this->groupHasEntry($setup['source'], $setup['source_entry_ids'][3]));
        $this->assertTrue($this->groupHasEntry($setup['target'], $setup['source_entry_ids'][3]));
    }

    public function test_finished_game_of_moved_entry_in_source_blocks_and_rolls_back(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);
        $obsolete = $this->gameBetweenSheetNumbers($setup['source'], $setup['source_entry_ids'], 1, 4);
        $context->finishGame($obsolete, $obsolete->singlesPlayer1())->assertOk();
        $sourceGames = $setup['source']->games()->orderBy('id')->pluck('id')->all();
        $targetGames = $setup['target']->games()->orderBy('id')->pluck('id')->all();

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $setup['source_entry_ids'][3], $setup['target'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.group.0', ReconcileGroupRoundRobinGamesAction::BLOCKED_MESSAGE);

        $this->assertTrue($this->groupHasEntry($setup['source'], $setup['source_entry_ids'][3]));
        $this->assertFalse($this->groupHasEntry($setup['target'], $setup['source_entry_ids'][3]));
        $this->assertSame(4, $setup['source']->groupEntries()->count());
        $this->assertSame(3, $setup['target']->groupEntries()->count());
        $this->assertSame($sourceGames, $setup['source']->games()->orderBy('id')->pluck('id')->all());
        $this->assertSame($targetGames, $setup['target']->games()->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GROUP_PLAYER_MOVED->value)->count());
    }

    public function test_in_progress_game_of_moved_entry_blocks_and_rolls_back(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context, setsToWin: 2);
        $obsolete = $this->gameBetweenSheetNumbers($setup['source'], $setup['source_entry_ids'], 1, 4);
        $context->recordSet($obsolete, setNumber: 1, player1Score: 11, player2Score: 5)->assertOk();

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $setup['source_entry_ids'][3], $setup['target'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.group.0', ReconcileGroupRoundRobinGamesAction::BLOCKED_MESSAGE);

        $this->assertTrue($this->groupHasEntry($setup['source'], $setup['source_entry_ids'][3]));
        $this->assertSame(GameStatus::InProgress, $obsolete->fresh()->status);
        $this->assertSame(3, $setup['target']->groupEntries()->count());
    }

    public function test_pending_with_forced_set_blocks_via_source_reconcile(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);
        $obsolete = $this->gameBetweenSheetNumbers($setup['source'], $setup['source_entry_ids'], 1, 4);
        $set = GameSet::query()->create([
            'game_id' => $obsolete->id,
            'set_number' => 1,
            'player1_score' => 11,
            'player2_score' => 5,
        ]);

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $setup['source_entry_ids'][3], $setup['target'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.group.0', ReconcileGroupRoundRobinGamesAction::BLOCKED_MESSAGE);

        $this->assertTrue($this->groupHasEntry($setup['source'], $setup['source_entry_ids'][3]));
        $this->assertTrue(GameSet::query()->whereKey($set->id)->exists());
        $this->assertSame(6, $setup['source']->games()->count());
        $this->assertSame(3, $setup['target']->games()->count());
    }

    public function test_target_finished_games_are_preserved_when_adding_moved_entry(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);
        $historical = $this->gameBetweenSheetNumbers($setup['target'], $setup['target_entry_ids'], 1, 2);
        $context->finishGame($historical, $historical->singlesPlayer1())->assertOk();
        $snapshot = $this->snapshotGame($historical->fresh(['sets']));
        $targetGameIds = $setup['target']->games()->orderBy('id')->pluck('id')->all();

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $setup['source_entry_ids'][3], $setup['target'])
            ->assertOk();

        $this->assertSame($snapshot, $this->snapshotGame($historical->fresh(['sets'])));
        $this->assertSame($targetGameIds, $this->gameIds($setup['target'], $targetGameIds));
        $this->assertSame(6, $setup['target']->games()->count());
    }

    public function test_source_g2_to_g1_leaves_one_entry_and_no_games(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $source = $context->createGroupWithPlayers($competition, $players, 'Grupo A');
        $target = $context->createGroup($competition, 'Grupo B');
        $context->generateRoundRobin($source)->assertCreated();
        $movedId = $this->entryIds($source)[1];

        $context->moveEntryBetweenGroupsViaApi($source, $movedId, $target)->assertOk();

        $this->assertSame(1, $source->groupEntries()->count());
        $this->assertSame(0, $source->games()->count());
        $this->assertSame(1, $target->groupEntries()->count());
        $this->assertSame(0, $target->games()->count());
        $this->assertTrue(Group::query()->whereKey($source->id)->exists());
    }

    public function test_moving_last_source_entry_leaves_empty_group(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $entry = $context->registerPlayer($competition, $player);
        $source = $context->createGroupWithPlayers($competition, [$player], 'Grupo A');
        $target = $context->createGroup($competition, 'Grupo B');

        $context->moveEntryBetweenGroupsViaApi($source, $entry, $target)->assertOk();

        $this->assertSame(0, $source->groupEntries()->count());
        $this->assertSame(0, $source->games()->count());
        $this->assertTrue(Group::query()->whereKey($source->id)->exists());
        $this->assertSame(1, $target->groupEntries()->count());
    }

    public function test_same_source_and_target_returns_422(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $setup['source_entry_ids'][0], $setup['source'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['target_group_id'])
            ->assertJsonPath(
                'errors.target_group_id.0',
                MoveCompetitionEntryBetweenGroupsAction::SAME_GROUP_MESSAGE,
            );

        $this->assertSame(4, $setup['source']->groupEntries()->count());
        $this->assertSame(3, $setup['target']->groupEntries()->count());
    }

    public function test_target_from_another_competition_returns_422(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);
        $other = $this->createGroupWithEntries($context, 2);

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $setup['source_entry_ids'][3], $other)
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.target_group_id.0',
                MoveCompetitionEntryBetweenGroupsAction::DIFFERENT_COMPETITION_MESSAGE,
            );

        $this->assertTrue($this->groupHasEntry($setup['source'], $setup['source_entry_ids'][3]));
        $this->assertSame(2, $other->groupEntries()->count());
    }

    public function test_entry_not_in_source_returns_422_without_touching_target(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);
        $foreignId = $setup['target_entry_ids'][0];
        $targetGames = $setup['target']->games()->orderBy('id')->pluck('id')->all();

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $foreignId, $setup['target'])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.competition_entry_id.0',
                MoveCompetitionEntryBetweenGroupsAction::NOT_IN_SOURCE_MESSAGE,
            );

        $this->assertSame(4, $setup['source']->groupEntries()->count());
        $this->assertSame(3, $setup['target']->groupEntries()->count());
        $this->assertSame($targetGames, $setup['target']->games()->orderBy('id')->pluck('id')->all());
    }

    public function test_move_is_blocked_when_bracket_exists(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);
        $context->createBracket($setup['competition'])->assertCreated();
        $entryId = $this->entryIds($setup['groupA'])[0];

        $context->moveEntryBetweenGroupsViaApi($setup['groupA'], $entryId, $setup['groupB'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', LateGroupMutationGuard::LOCK_MESSAGE);

        $this->assertTrue($this->groupHasEntry($setup['groupA'], $entryId));
        $this->assertTrue($setup['competition']->brackets()->exists());
    }

    public function test_move_is_blocked_when_tournament_is_finished(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);
        $setup['source']->competition->tournament->update([
            'status' => TournamentStatus::Finished,
            'closed_at' => now(),
        ]);

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $setup['source_entry_ids'][3], $setup['target'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament'])
            ->assertJsonPath('errors.tournament.0', TournamentLifecycleGuard::LOCK_MESSAGE);

        $this->assertTrue($this->groupHasEntry($setup['source'], $setup['source_entry_ids'][3]));
    }

    public function test_doubles_move_keeps_pair_entry_and_members(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(14);
        $pairs = [];
        for ($index = 0; $index < 7; $index++) {
            $pairs[] = [$players[$index * 2], $players[$index * 2 + 1]];
        }
        $entries = $context->registerPairs($competition, $pairs);
        $source = $context->createGroupWithEntries($competition, array_slice($entries, 0, 4), 'Grupo A');
        $target = $context->createGroupWithEntries($competition, array_slice($entries, 4, 3), 'Grupo B');
        $context->generateRoundRobin($source)->assertCreated();
        $context->generateRoundRobin($target)->assertCreated();
        $moved = $entries[3];
        $memberCount = CompetitionEntryMember::query()
            ->where('competition_entry_id', $moved->id)
            ->count();
        $groupEntryId = (int) GroupEntry::query()
            ->where('group_id', $source->id)
            ->where('competition_entry_id', $moved->id)
            ->value('id');

        $context->moveEntryBetweenGroupsViaApi($source, $moved, $target)->assertOk();

        $this->assertSame(3, $source->groupEntries()->count());
        $this->assertSame(4, $target->groupEntries()->count());
        $this->assertSame($groupEntryId, (int) GroupEntry::query()
            ->where('competition_entry_id', $moved->id)
            ->value('id'));
        $this->assertTrue(CompetitionEntry::query()->whereKey($moved->id)->exists());
        $this->assertSame(
            $memberCount,
            CompetitionEntryMember::query()->where('competition_entry_id', $moved->id)->count(),
        );
        $this->assertTrue(GroupScheduleCompletion::hasCompleteGamesSchedule($source->fresh()));
        $this->assertTrue(GroupScheduleCompletion::hasCompleteGamesSchedule($target->fresh()));
    }

    public function test_team_groups_are_rejected_and_not_mutated(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 4, 4);
        $source = $context->createGroupWithEntries($competition, array_slice($entries, 0, 2), 'Grupo A');
        $target = $context->createGroupWithEntries($competition, array_slice($entries, 2, 2), 'Grupo B');
        $context->generateTeamRoundRobin($source)->assertCreated();
        $context->generateTeamRoundRobin($target)->assertCreated();
        $tiesBefore = TeamTie::query()->count();

        $context->moveEntryBetweenGroupsViaApi($source, $entries[0], $target)
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.group.0',
                'La generación de partidos individuales no aplica a competencias por equipos.',
            );

        $this->assertTrue($this->groupHasEntry($source, (int) $entries[0]->id));
        $this->assertSame(2, $source->groupEntries()->count());
        $this->assertSame(2, $target->groupEntries()->count());
        $this->assertSame($tiesBefore, TeamTie::query()->count());
    }

    public function test_print_source_g3_and_target_g4_after_move(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $setup['source_entry_ids'][3], $setup['target'])
            ->assertOk();

        $this->getJson($context->apiUrl("groups/{$setup['source']->id}/print"))
            ->assertOk()
            ->assertJsonCount(3, 'data.matches')
            ->assertJsonPath('data.sheet_kind', 'g3');

        $this->getJson($context->apiUrl("groups/{$setup['target']->id}/print"))
            ->assertOk()
            ->assertJsonCount(6, 'data.matches')
            ->assertJsonPath('data.sheet_kind', 'g4');
    }

    public function test_standings_follow_new_composition_on_source_and_target(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);
        $movedId = $setup['source_entry_ids'][3];

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $movedId, $setup['target'])->assertOk();

        $sourceStandings = collect($this->getJson($context->apiUrl("groups/{$setup['source']->id}/standings"))
            ->assertOk()
            ->json('data'))
            ->pluck('competition_entry_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $targetStandings = collect($this->getJson($context->apiUrl("groups/{$setup['target']->id}/standings"))
            ->assertOk()
            ->json('data'))
            ->pluck('competition_entry_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertNotContains($movedId, $sourceStandings);
        $this->assertContains($movedId, $targetStandings);
        $this->assertCount(3, $sourceStandings);
        $this->assertCount(4, $targetStandings);
    }

    public function test_status_stays_group_stage_in_progress_after_pending_move(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $setup['source_entry_ids'][3], $setup['target'])
            ->assertOk();

        $this->getJson($context->apiUrl("competitions/{$setup['source']->competition_id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'group_stage_in_progress');
    }

    public function test_successful_move_audits_only_player_moved(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);
        $movedId = $setup['source_entry_ids'][3];
        Activity::query()->delete();

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $movedId, $setup['target'])->assertOk();

        $activity = Activity::query()
            ->where('description', AuditAction::GROUP_PLAYER_MOVED->value)
            ->sole();

        $this->assertSame('groups', $activity->log_name);
        $this->assertSame(Group::class, $activity->subject_type);
        $this->assertSame($setup['source']->id, $activity->subject_id);
        $this->assertSame($setup['source']->id, data_get($activity->properties, 'summary.source_group_id'));
        $this->assertSame($setup['target']->id, data_get($activity->properties, 'summary.target_group_id'));
        $this->assertSame($movedId, data_get($activity->properties, 'summary.competition_entry_id'));
        $this->assertSame($setup['source']->name, data_get($activity->properties, 'summary.source_group_name'));
        $this->assertSame($setup['target']->name, data_get($activity->properties, 'summary.target_group_name'));
        $this->assertNotEmpty(data_get($activity->properties, 'summary.display_name'));
        $this->assertSame(1, Activity::query()->count());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GROUP_PLAYER_REMOVED->value)->count());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GROUP_PLAYER_ASSIGNED->value)->count());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GROUPS_ROUND_ROBIN_GENERATED->value)->count());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GAME_DELETED->value)->count());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GAME_CREATED->value)->count());
    }

    public function test_failed_move_does_not_emit_audit(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);
        $obsolete = $this->gameBetweenSheetNumbers($setup['source'], $setup['source_entry_ids'], 1, 4);
        $context->finishGame($obsolete, $obsolete->singlesPlayer1())->assertOk();
        Activity::query()->delete();

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $setup['source_entry_ids'][3], $setup['target'])
            ->assertUnprocessable();

        $this->assertSame(0, Activity::query()->where('description', AuditAction::GROUP_PLAYER_MOVED->value)->count());
    }

    public function test_failure_during_target_reconcile_rolls_back_source_target_and_audit(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);
        $movedId = $setup['source_entry_ids'][3];
        $sourceGames = $setup['source']->games()->orderBy('id')->pluck('id')->all();
        $targetGames = $setup['target']->games()->orderBy('id')->pluck('id')->all();
        $groupEntryId = (int) GroupEntry::query()
            ->where('group_id', $setup['source']->id)
            ->where('competition_entry_id', $movedId)
            ->value('id');
        Activity::query()->delete();

        Game::creating(function (): void {
            throw new RuntimeException('forced target missing-pair failure');
        });

        try {
            app(MoveCompetitionEntryBetweenGroupsAction::class)(
                $setup['source']->fresh(),
                $movedId,
                (int) $setup['target']->id,
            );
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced target missing-pair failure', $exception->getMessage());
        } catch (ValidationException $exception) {
            $this->fail('Expected RuntimeException, got ValidationException: '.json_encode($exception->errors()));
        }

        $this->assertTrue($this->groupHasEntry($setup['source'], $movedId));
        $this->assertFalse($this->groupHasEntry($setup['target'], $movedId));
        $this->assertSame($groupEntryId, (int) GroupEntry::query()
            ->where('competition_entry_id', $movedId)
            ->value('id'));
        $this->assertSame((int) $setup['source']->id, (int) GroupEntry::query()
            ->whereKey($groupEntryId)
            ->value('group_id'));
        $this->assertSame($sourceGames, $setup['source']->games()->orderBy('id')->pluck('id')->all());
        $this->assertSame($targetGames, $setup['target']->games()->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GROUP_PLAYER_MOVED->value)->count());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
    }

    public function test_competition_entry_and_members_remain_intact(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);
        $movedId = $setup['source_entry_ids'][3];
        $memberCount = CompetitionEntryMember::query()
            ->where('competition_entry_id', $movedId)
            ->count();

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $movedId, $setup['target'])->assertOk();

        $this->assertTrue(CompetitionEntry::query()->whereKey($movedId)->exists());
        $this->assertSame(
            $memberCount,
            CompetitionEntryMember::query()->where('competition_entry_id', $movedId)->count(),
        );
    }

    public function test_group_entry_id_and_status_metadata_are_preserved(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);
        $movedId = $setup['source_entry_ids'][3];
        $groupEntry = GroupEntry::query()
            ->where('group_id', $setup['source']->id)
            ->where('competition_entry_id', $movedId)
            ->firstOrFail();
        $groupEntry->update([
            'status' => GroupPlayerStatus::Withdrawn,
            'status_reason' => GroupPlayerStatusReason::NoShow,
            'status_notes' => 'conservar en el move',
            'status_changed_at' => now()->subHour(),
        ]);
        $changedAt = $groupEntry->fresh()->status_changed_at?->toJSON();

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $movedId, $setup['target'])->assertOk();

        $moved = GroupEntry::query()->whereKey($groupEntry->id)->firstOrFail();
        $this->assertSame((int) $setup['target']->id, (int) $moved->group_id);
        $this->assertSame(GroupPlayerStatus::Withdrawn, $moved->status);
        $this->assertSame(GroupPlayerStatusReason::NoShow, $moved->status_reason);
        $this->assertSame('conservar en el move', $moved->status_notes);
        $this->assertSame($changedAt, $moved->status_changed_at?->toJSON());
    }

    public function test_inverse_move_restores_membership_when_all_pending(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);
        $movedId = $setup['source_entry_ids'][3];

        $context->moveEntryBetweenGroupsViaApi($setup['source'], $movedId, $setup['target'])->assertOk();
        $context->moveEntryBetweenGroupsViaApi($setup['target']->fresh(), $movedId, $setup['source']->fresh())
            ->assertOk();

        $this->assertTrue($this->groupHasEntry($setup['source'], $movedId));
        $this->assertFalse($this->groupHasEntry($setup['target'], $movedId));
        $this->assertSame(4, $setup['source']->groupEntries()->count());
        $this->assertSame(3, $setup['target']->groupEntries()->count());
        $this->assertTrue(GroupScheduleCompletion::hasCompleteGamesSchedule($setup['source']->fresh()));
        $this->assertTrue(GroupScheduleCompletion::hasCompleteGamesSchedule($setup['target']->fresh()));
        $this->getJson($context->apiUrl("groups/{$setup['source']->id}/print"))
            ->assertOk()
            ->assertJsonPath('data.sheet_kind', 'g4');
        $this->getJson($context->apiUrl("groups/{$setup['target']->id}/print"))
            ->assertOk()
            ->assertJsonPath('data.sheet_kind', 'g3');
    }

    public function test_move_from_higher_id_group_to_lower_id_group_uses_deterministic_lock_order(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $lower = $context->createGroup($competition, 'Grupo A');
        $higher = $context->createGroupWithPlayers($competition, $players, 'Grupo B');
        $this->assertGreaterThan($lower->id, $higher->id);
        $movedId = $this->entryIds($higher)[0];

        $context->moveEntryBetweenGroupsViaApi($higher, $movedId, $lower)->assertOk();

        $this->assertTrue($this->groupHasEntry($lower, $movedId));
        $this->assertFalse($this->groupHasEntry($higher, $movedId));
        $this->assertSame(1, $lower->groupEntries()->count());
        $this->assertSame(2, $higher->groupEntries()->count());
    }

    public function test_scorekeeper_cannot_move_group_entry(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG4AndG3($context);

        $context->moveEntryBetweenGroupsViaApi(
            $setup['source'],
            $setup['source_entry_ids'][3],
            $setup['target'],
            ['scorekeeper'],
        )->assertForbidden();

        $this->assertTrue($this->groupHasEntry($setup['source'], $setup['source_entry_ids'][3]));
    }

    /**
     * @return array{
     *     source: Group,
     *     target: Group,
     *     source_entry_ids: list<int>,
     *     target_entry_ids: list<int>
     * }
     */
    private function createG4AndG3(TournamentTestContext $context, int $setsToWin = 1): array
    {
        $competition = $context->createCompetition($setsToWin);
        $players = $context->createPlayers(7);
        $context->registerPlayers($competition, $players);
        $source = $context->createGroupWithPlayers($competition, array_slice($players, 0, 4), 'Grupo A');
        $target = $context->createGroupWithPlayers($competition, array_slice($players, 4, 3), 'Grupo B');
        $context->generateRoundRobin($source)->assertCreated();
        $context->generateRoundRobin($target)->assertCreated();

        return [
            'source' => $source->fresh(),
            'target' => $target->fresh(),
            'source_entry_ids' => $this->entryIds($source),
            'target_entry_ids' => $this->entryIds($target),
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
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function snapshotAllGamesByIds(Group $group, array $ids): array
    {
        return $group->games()->whereIn('id', $ids)->orderBy('id')->get()->map(
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
