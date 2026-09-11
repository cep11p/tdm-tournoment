<?php

namespace Tests\Feature\Group;

use App\Actions\GroupPlayer\AssignPlayerToGroupAction;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Enums\TournamentStatus;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Models\GameSet;
use App\Models\Group;
use App\Models\TeamTie;
use App\Support\Competition\LateGroupMutationGuard;
use App\Support\Group\GroupRoundRobinSlotAllocator;
use App\Support\Group\GroupSheetNumbering;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class AtomicAssignEntryToGroupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_assigning_fourth_entry_to_pending_g3_completes_fixture_atomically(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3WithFixture($context);
        $originalIds = $setup['original_game_ids'];

        $response = $context->assignEntryToGroupViaApi($setup['group'], $setup['late_entry']);

        $response
            ->assertCreated()
            ->assertJsonPath('data.competition_entry_id', $setup['late_entry']->id)
            ->assertJsonPath('data.group_id', $setup['group']->id);

        $this->assertArrayNotHasKey('games_created', $response->json('data'));

        $group = $setup['group']->fresh();
        $this->assertSame(4, $group->groupEntries()->count());
        $this->assertSame(6, $group->games()->count());
        $this->assertSame($originalIds, $this->gameIds($group, $originalIds));
        $this->assertUniqueGroupPairs($group, 6);

        $standings = $this->getJson($context->apiUrl("groups/{$group->id}/standings"))
            ->assertOk()
            ->json('data');
        $lateStanding = collect($standings)->firstWhere(
            'competition_entry_id',
            $setup['late_entry']->id,
        );

        $this->assertNotNull($lateStanding);
        $this->assertSame(0, $lateStanding['played']);
        $this->assertSame(0, $lateStanding['won']);
        $this->assertSame(0, $lateStanding['lost']);
    }

    public function test_assigning_to_g3_with_finished_game_preserves_historical_result(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3WithFixture($context);
        $historical = $this->gameBetweenSheetNumbers($setup['group'], $setup['entry_ids'], 2, 3);
        $context->finishGame($historical, $historical->singlesPlayer1())->assertOk();
        $snapshot = $this->snapshotGame($historical->fresh(['sets']));

        $context->assignEntryToGroupViaApi($setup['group'], $setup['late_entry'])->assertCreated();

        $this->assertSame($snapshot, $this->snapshotGame($historical->fresh(['sets'])));
        $this->assertSame(6, $setup['group']->games()->count());
        $this->assertSame(3, (int) $historical->fresh()->group_round);
        $this->assertSame(1, (int) $historical->fresh()->group_match);
    }

    public function test_assigning_to_g3_with_in_progress_game_preserves_metadata(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3WithFixture($context, setsToWin: 2);
        $historical = $this->gameBetweenSheetNumbers($setup['group'], $setup['entry_ids'], 2, 3);
        $context->recordSet($historical, setNumber: 1, player1Score: 11, player2Score: 5)->assertOk();
        $snapshot = $this->snapshotGame($historical->fresh(['sets']));

        $this->assertSame(GameStatus::InProgress, $historical->fresh()->status);

        $context->assignEntryToGroupViaApi($setup['group'], $setup['late_entry'])->assertCreated();

        $this->assertSame($snapshot, $this->snapshotGame($historical->fresh(['sets'])));
        $this->assertSame(GameStatus::InProgress, $historical->fresh()->status);
        $this->assertSame(6, $setup['group']->games()->count());
    }

    public function test_assigning_to_group_without_fixture_creates_complete_round_robin(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, array_slice($players, 0, 2));

        $this->assertSame(0, $group->games()->count());

        $context->assignPlayerToGroupViaApi($group, $players[2])->assertCreated();

        $this->assertSame(3, $group->groupEntries()->count());
        $this->assertSame(3, $group->games()->count());
        $this->assertUniqueGroupPairs($group, 3);
    }

    public function test_first_assignment_does_not_create_games_and_second_creates_g2(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroup($competition);

        $context->assignPlayerToGroupViaApi($group, $players[0])->assertCreated();

        $this->assertSame(1, $group->groupEntries()->count());
        $this->assertSame(0, $group->games()->count());

        $context->assignPlayerToGroupViaApi($group, $players[1])->assertCreated();

        $this->assertSame(2, $group->groupEntries()->count());
        $this->assertSame(1, $group->games()->count());
        $this->assertUniqueGroupPairs($group, 1);
    }

    public function test_sync_failure_rolls_back_assignment_games_and_audit(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, array_slice($players, 0, 2));
        Activity::query()->delete();

        Game::creating(function (): void {
            throw new RuntimeException('forced round-robin failure');
        });

        $threw = false;

        try {
            app(AssignPlayerToGroupAction::class)([
                'group_id' => $group->id,
                'player_id' => $players[2]->id,
            ]);
        } catch (RuntimeException $exception) {
            $threw = true;
            $this->assertSame('forced round-robin failure', $exception->getMessage());
        }

        $this->assertTrue($threw);
        $this->assertSame(2, $group->groupEntries()->count());
        $this->assertSame(0, $group->games()->count());
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_doubles_assignment_completes_fixture_using_competition_entries(): void
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
        $group = $context->createGroupWithEntries($competition, array_slice($entries, 0, 3));
        $context->generateRoundRobin($group)->assertCreated();
        $originalIds = $group->games()->orderBy('id')->pluck('id')->all();

        $context->assignEntryToGroupViaApi($group, $entries[3])->assertCreated();

        $this->assertSame(4, $group->groupEntries()->count());
        $this->assertSame(6, $group->games()->count());
        $this->assertSame($originalIds, $this->gameIds($group, $originalIds));
        $this->assertUniqueGroupPairs($group, 6);
    }

    public function test_assignment_is_blocked_when_bracket_exists(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);
        [$latePlayer] = $context->createPlayers(1);
        $lateEntry = $context->registerPlayer($setup['competition'], $latePlayer);
        $context->createBracket($setup['competition'])->assertCreated();
        $gamesBefore = Game::query()->where('group_id', $setup['groupA']->id)->count();

        $context->assignEntryToGroupViaApi($setup['groupA'], $lateEntry)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', LateGroupMutationGuard::LOCK_MESSAGE);

        $this->assertSame(2, $setup['groupA']->groupEntries()->count());
        $this->assertSame($gamesBefore, $setup['groupA']->games()->count());
    }

    public function test_assignment_is_blocked_when_tournament_is_finished(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3WithFixture($context);
        $setup['group']->competition->tournament->update([
            'status' => TournamentStatus::Finished,
            'closed_at' => now(),
        ]);
        $entriesBefore = $setup['group']->groupEntries()->count();
        $gamesBefore = $setup['group']->games()->count();

        $context->assignEntryToGroupViaApi($setup['group'], $setup['late_entry'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament'])
            ->assertJsonPath('errors.tournament.0', TournamentLifecycleGuard::LOCK_MESSAGE);

        $this->assertSame($entriesBefore, $setup['group']->groupEntries()->count());
        $this->assertSame($gamesBefore, $setup['group']->games()->count());
    }

    public function test_assignment_rejects_entry_from_another_competition_without_side_effects(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3WithFixture($context);
        $otherCompetition = $context->createCompetition();
        [$otherPlayer] = $context->createPlayers(1);
        $foreignEntry = $context->registerPlayer($otherCompetition, $otherPlayer);
        $gamesBefore = $setup['group']->games()->count();

        $context->assignEntryToGroupViaApi($setup['group'], $foreignEntry)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition_entry_id']);

        $this->assertSame(3, $setup['group']->groupEntries()->count());
        $this->assertSame($gamesBefore, $setup['group']->games()->count());
    }

    public function test_already_assigned_entry_is_rejected_without_changing_fixture(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3WithFixture($context);
        $existingEntryId = $setup['entry_ids'][0];
        $gamesBefore = $setup['group']->games()->count();

        $this->postJson($context->apiUrl("groups/{$setup['group']->id}/players"), [
            'competition_entry_id' => $existingEntryId,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);

        $this->assertSame(3, $setup['group']->groupEntries()->count());
        $this->assertSame($gamesBefore, $setup['group']->games()->count());
    }

    public function test_team_assignment_does_not_create_individual_games_or_team_ties(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(2);
        $entries = $context->registerTeams($competition, 2, 2);
        $group = $context->createGroup($competition);

        $context->assignEntryToGroupViaApi($group, $entries[0])->assertCreated();
        $context->assignEntryToGroupViaApi($group, $entries[1])->assertCreated();

        $this->assertSame(2, $group->groupEntries()->count());
        $this->assertSame(0, $group->games()->count());
        $this->assertSame(0, TeamTie::query()->where('group_id', $group->id)->count());
    }

    public function test_print_g4_succeeds_immediately_after_assigning_fourth_entry(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3WithFixture($context);

        $context->assignEntryToGroupViaApi($setup['group'], $setup['late_entry'])->assertCreated();

        $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->assertJsonCount(6, 'data.matches')
            ->assertJsonPath('data.sheet_kind', 'g4');
    }

    public function test_successful_assignment_audits_player_assigned_and_round_robin(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3WithFixture($context);
        Activity::query()->delete();

        $context->assignEntryToGroupViaApi($setup['group'], $setup['late_entry'])->assertCreated();

        $assigned = Activity::query()
            ->where('description', AuditAction::GROUP_PLAYER_ASSIGNED->value)
            ->sole();
        $generated = Activity::query()
            ->where('description', AuditAction::GROUPS_ROUND_ROBIN_GENERATED->value)
            ->sole();

        $this->assertSame($setup['late_entry']->id, data_get($assigned->properties, 'new.competition_entry_id'));
        $this->assertSame(3, data_get($generated->properties, 'summary.existing_games_before'));
        $this->assertSame(3, data_get($generated->properties, 'summary.games_created'));
        $this->assertSame(6, data_get($generated->properties, 'summary.games_total_after'));
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GAME_CREATED->value)->count());
    }

    public function test_failed_assignment_does_not_persist_audit_events(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3WithFixture($context);
        Activity::query()->delete();

        Game::creating(function (): void {
            throw new RuntimeException('forced round-robin failure');
        });

        try {
            app(AssignPlayerToGroupAction::class)([
                'group_id' => $setup['group']->id,
                'competition_entry_id' => $setup['late_entry']->id,
            ]);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced round-robin failure', $exception->getMessage());
        }

        $this->assertSame(0, Activity::query()->count());
        $this->assertSame(3, $setup['group']->groupEntries()->count());
        $this->assertSame(3, $setup['group']->games()->count());
    }

    public function test_duplicate_assignment_is_not_idempotent_and_does_not_duplicate_games(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3WithFixture($context);
        $context->assignEntryToGroupViaApi($setup['group'], $setup['late_entry'])->assertCreated();
        $this->assertSame(6, $setup['group']->games()->count());

        $context->assignEntryToGroupViaApi($setup['group'], $setup['late_entry'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);

        $this->assertSame(4, $setup['group']->groupEntries()->count());
        $this->assertSame(6, $setup['group']->games()->count());
        $this->assertUniqueGroupPairs($setup['group'], 6);
        $this->assertSame('sqlite', DB::connection()->getDriverName());
    }

    /**
     * @return array{
     *     group: Group,
     *     entry_ids: list<int>,
     *     original_game_ids: list<int>,
     *     late_entry: CompetitionEntry
     * }
     */
    private function createG3WithFixture(TournamentTestContext $context, int $setsToWin = 1): array
    {
        $competition = $context->createCompetition($setsToWin);
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $entryIds = $group->groupEntries()
            ->orderBy('competition_entry_id')
            ->pluck('competition_entry_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        [$latePlayer] = $context->createPlayers(1);
        $lateEntry = $context->registerPlayer($group->competition, $latePlayer);

        return [
            'group' => $group->fresh(),
            'entry_ids' => $entryIds,
            'original_game_ids' => $group->games()->orderBy('id')->pluck('id')->all(),
            'late_entry' => $lateEntry,
        ];
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
