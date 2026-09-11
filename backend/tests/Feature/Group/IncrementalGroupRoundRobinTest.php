<?php

namespace Tests\Feature\Group;

use App\Actions\Group\GenerateGroupRoundRobinGamesAction;
use App\Actions\Group\PersistGroupEntryAction;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Enums\TournamentStatus;
use App\Models\Game;
use App\Models\GameSet;
use App\Models\Group;
use App\Support\Competition\LateGroupMutationGuard;
use App\Support\Group\GroupRoundRobinSlotAllocator;
use App\Support\Group\GroupSheetNumbering;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class IncrementalGroupRoundRobinTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_initial_sync_creates_g3_fixture(): void
    {
        $context = $this->tournamentContext();
        $group = $this->createGroupWithEntries($context, 3);

        $response = $context->generateRoundRobin($group);

        $response
            ->assertCreated()
            ->assertJsonCount(3, 'data');
        $this->assertSame(3, $group->games()->count());
        $this->assertUniqueGroupPairs($group, 3);
    }

    public function test_complete_fixture_sync_is_idempotent(): void
    {
        $context = $this->tournamentContext();
        $group = $this->createGroupWithEntries($context, 3);
        $context->generateRoundRobin($group)->assertCreated();
        $originalIds = $group->games()->orderBy('id')->pluck('id')->all();

        $context->generateRoundRobin($group)
            ->assertCreated()
            ->assertJsonCount(0, 'data');

        $this->assertSame($originalIds, $group->games()->orderBy('id')->pluck('id')->all());
        $this->assertUniqueGroupPairs($group, 3);
    }

    public function test_g3_to_g4_with_all_pending_games_creates_only_missing_pairs(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3ReadyToAddFourth($context);
        $originalIds = $setup['original_game_ids'];

        $response = $context->generateRoundRobin($setup['group']);

        $response
            ->assertCreated()
            ->assertJsonCount(3, 'data');

        $group = $setup['group']->fresh();
        $this->assertSame(6, $group->games()->count());
        $this->assertSame($originalIds, $this->gameIds($group, $originalIds));
        $this->assertUniqueGroupPairs($group, 6);
        $this->assertG3ToG4AppendedSlots($group, $setup['entry_ids']);
    }

    public function test_g3_to_g4_preserves_finished_game_metadata_and_results(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3ReadyToAddFourth($context);
        $historical = $this->gameBetweenSheetNumbers($setup['group'], $setup['entry_ids'], 2, 3);
        $context->finishGame($historical, $historical->singlesPlayer1())->assertOk();
        $snapshot = $this->snapshotGame($historical->fresh(['sets']));

        $context->generateRoundRobin($setup['group'])->assertCreated();

        $this->assertSame($snapshot, $this->snapshotGame($historical->fresh(['sets'])));
        $this->assertSame(6, $setup['group']->games()->count());
        $this->assertSame(3, (int) $historical->fresh()->group_round);
        $this->assertSame(1, (int) $historical->fresh()->group_match);
    }

    public function test_g3_to_g4_preserves_in_progress_game_metadata(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3ReadyToAddFourth($context, setsToWin: 2);
        $historical = $this->gameBetweenSheetNumbers($setup['group'], $setup['entry_ids'], 2, 3);
        $context->recordSet($historical, setNumber: 1, player1Score: 11, player2Score: 5)->assertOk();
        $snapshot = $this->snapshotGame($historical->fresh(['sets']));

        $this->assertSame(GameStatus::InProgress, $historical->fresh()->status);

        $context->generateRoundRobin($setup['group'])->assertCreated();

        $this->assertSame($snapshot, $this->snapshotGame($historical->fresh(['sets'])));
        $this->assertSame(GameStatus::InProgress, $historical->fresh()->status);
        $this->assertSame(6, $setup['group']->games()->count());
    }

    public function test_g3_to_g4_does_not_rewrite_sets_of_existing_games(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3ReadyToAddFourth($context);
        $finished = $setup['group']->games()->orderBy('id')->get();

        foreach ($finished as $index => $game) {
            $winner = $index === 2 ? $game->singlesPlayer2() : $game->singlesPlayer1();
            $context->finishGame($game, $winner)->assertOk();
        }

        $setRows = GameSet::query()
            ->whereIn('game_id', $finished->pluck('id'))
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

        $context->generateRoundRobin($setup['group'])->assertCreated();

        $this->assertSame(
            $setRows,
            GameSet::query()
                ->whereIn('game_id', $finished->pluck('id'))
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

    public function test_g3_to_g4_appends_when_official_slot_is_occupied(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3ReadyToAddFourth($context);

        $context->generateRoundRobin($setup['group'])->assertCreated();

        $this->assertG3ToG4AppendedSlots($setup['group']->fresh(), $setup['entry_ids']);
    }

    public function test_resync_after_g3_to_g4_creates_zero_games(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3ReadyToAddFourth($context);
        $context->generateRoundRobin($setup['group'])->assertCreated();

        $context->generateRoundRobin($setup['group'])
            ->assertCreated()
            ->assertJsonCount(0, 'data');

        $this->assertSame(6, $setup['group']->games()->count());
        $this->assertUniqueGroupPairs($setup['group'], 6);
    }

    public function test_doubles_sync_creates_only_missing_pairs(): void
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
        $this->assertSame(3, $group->games()->count());

        app(PersistGroupEntryAction::class)($group, $entries[3]);
        $context->generateRoundRobin($group)
            ->assertCreated()
            ->assertJsonCount(3, 'data');

        $this->assertSame(6, $group->games()->count());
        $this->assertUniqueGroupPairs($group, 6);

        $gameEntryIds = $group->games()
            ->get()
            ->flatMap(fn (Game $game): array => [(int) $game->entry1_id, (int) $game->entry2_id])
            ->unique()
            ->sort()
            ->values()
            ->all();
        $this->assertSame(
            collect($entries)->map(fn ($entry): int => (int) $entry->id)->sort()->values()->all(),
            $gameEntryIds,
        );
    }

    public function test_sync_only_uses_entries_of_the_same_group(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3ReadyToAddFourth($context);
        $otherCompetition = $context->createCompetition();
        [$otherPlayer] = $context->createPlayers(1);
        $foreignEntry = $context->registerPlayer($otherCompetition, $otherPlayer);

        $context->generateRoundRobin($setup['group'])->assertCreated();

        $gameEntryIds = $setup['group']->games()
            ->get()
            ->flatMap(fn (Game $game): array => [(int) $game->entry1_id, (int) $game->entry2_id])
            ->unique()
            ->all();

        $this->assertNotContains((int) $foreignEntry->id, $gameEntryIds);
        $this->assertSame(
            collect($setup['entry_ids'])->sort()->values()->all(),
            collect($gameEntryIds)->sort()->values()->all(),
        );
    }

    public function test_sync_is_blocked_when_bracket_exists(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);
        $context->createBracket($setup['competition'])->assertCreated();

        $context->generateRoundRobin($setup['groupA'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', LateGroupMutationGuard::LOCK_MESSAGE);
    }

    public function test_sync_is_blocked_when_tournament_is_finished(): void
    {
        $context = $this->tournamentContext();
        $group = $this->createGroupWithEntries($context, 3);
        $context->generateRoundRobin($group)->assertCreated();
        $group->competition->tournament->update([
            'status' => TournamentStatus::Finished,
            'closed_at' => now(),
        ]);

        $context->generateRoundRobin($group)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament'])
            ->assertJsonPath('errors.tournament.0', TournamentLifecycleGuard::LOCK_MESSAGE);
    }

    public function test_team_round_robin_remains_non_incremental(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();

        $context->generateTeamRoundRobin($group)->assertUnprocessable();

        try {
            app(GenerateGroupRoundRobinGamesAction::class)($group->fresh());
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'La generación de partidos individuales no aplica a competencias por equipos.',
                $exception->errors()['group'][0],
            );
        }
    }

    public function test_sequential_resync_does_not_duplicate_pairs_under_row_lock(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3ReadyToAddFourth($context);

        $context->generateRoundRobin($setup['group'])->assertCreated();
        $context->generateRoundRobin($setup['group'])->assertCreated();
        $context->generateRoundRobin($setup['group'])->assertCreated();

        $this->assertSame(6, $setup['group']->games()->count());
        $this->assertUniqueGroupPairs($setup['group'], 6);
        $this->assertSame('sqlite', DB::connection()->getDriverName());
    }

    public function test_incremental_sync_audits_created_and_existing_counts_without_game_created_events(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3ReadyToAddFourth($context);
        Activity::query()->delete();

        $context->generateRoundRobin($setup['group'])->assertCreated();

        $activity = Activity::query()
            ->where('description', AuditAction::GROUPS_ROUND_ROBIN_GENERATED->value)
            ->sole();

        $this->assertSame(3, data_get($activity->properties, 'summary.existing_games_before'));
        $this->assertSame(3, data_get($activity->properties, 'summary.games_created'));
        $this->assertSame(6, data_get($activity->properties, 'summary.games_total_after'));
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GAME_CREATED->value)->count());

        $countAfterIncremental = Activity::query()->count();
        $context->generateRoundRobin($setup['group'])->assertCreated();
        $this->assertSame($countAfterIncremental, Activity::query()->count());
    }

    public function test_print_still_resolves_complete_g4_pairs_after_incremental_sync(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createG3ReadyToAddFourth($context);
        $context->generateRoundRobin($setup['group'])->assertCreated();

        $response = $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"));

        $response
            ->assertOk()
            ->assertJsonCount(6, 'data.matches')
            ->assertJsonPath('data.sheet_kind', 'g4');

        $oneVsFour = $this->gameBetweenSheetNumbers($setup['group']->fresh(), $setup['entry_ids'], 1, 4);
        $this->assertSame(4, (int) $oneVsFour->group_round);
        $this->assertSame(1, (int) $oneVsFour->group_match);

        $printOneVsFour = collect($response->json('data.matches'))->first(
            fn (array $match): bool => (int) $match['game_id'] === (int) $oneVsFour->id,
        );

        $this->assertNotNull($printOneVsFour);
        $this->assertSame(3, (int) $printOneVsFour['group_round']);
        $this->assertSame(1, (int) $printOneVsFour['group_match']);
    }

    public function test_berger_group_gains_only_missing_pairs_when_a_player_is_added(): void
    {
        $context = $this->tournamentContext();
        $group = $this->createGroupWithEntries($context, 6);
        $context->generateRoundRobin($group)->assertCreated();
        $originalIds = $group->games()->orderBy('id')->pluck('id')->all();
        $this->assertCount(15, $originalIds);

        [$latePlayer] = $context->createPlayers(1);
        $lateEntry = $context->registerPlayer($group->competition, $latePlayer);
        app(PersistGroupEntryAction::class)($group, $lateEntry);

        $context->generateRoundRobin($group)
            ->assertCreated()
            ->assertJsonCount(6, 'data');

        $this->assertSame(21, $group->games()->count());
        $this->assertSame($originalIds, $this->gameIds($group, $originalIds));
        $this->assertUniqueGroupPairs($group, 21);
    }

    /**
     * @return array{
     *     group: Group,
     *     entry_ids: list<int>,
     *     original_game_ids: list<int>
     * }
     */
    private function createG3ReadyToAddFourth(TournamentTestContext $context, int $setsToWin = 1): array
    {
        $group = $this->createGroupWithEntries($context, 3, $setsToWin);
        $context->generateRoundRobin($group)->assertCreated();
        $originalIds = $group->games()->orderBy('id')->pluck('id')->all();
        $entryIds = $group->groupEntries()
            ->orderBy('competition_entry_id')
            ->pluck('competition_entry_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        [$latePlayer] = $context->createPlayers(1);
        $lateEntry = $context->registerPlayer($group->competition, $latePlayer);
        app(PersistGroupEntryAction::class)($group, $lateEntry);

        $entryIds[] = (int) $lateEntry->id;

        return [
            'group' => $group->fresh(),
            'entry_ids' => $entryIds,
            'original_game_ids' => $originalIds,
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
     * @param  list<int>  $entryIds
     */
    private function assertG3ToG4AppendedSlots(Group $group, array $entryIds): void
    {
        $twoVsFour = $this->gameBetweenSheetNumbers($group, $entryIds, 2, 4);
        $threeVsFour = $this->gameBetweenSheetNumbers($group, $entryIds, 3, 4);
        $oneVsFour = $this->gameBetweenSheetNumbers($group, $entryIds, 1, 4);
        $twoVsThree = $this->gameBetweenSheetNumbers($group, $entryIds, 2, 3);

        $this->assertSame(1, (int) $twoVsFour->group_round);
        $this->assertSame(2, (int) $twoVsFour->group_match);
        $this->assertSame(2, (int) $threeVsFour->group_round);
        $this->assertSame(2, (int) $threeVsFour->group_match);
        $this->assertSame(3, (int) $twoVsThree->group_round);
        $this->assertSame(1, (int) $twoVsThree->group_match);
        $this->assertSame(4, (int) $oneVsFour->group_round);
        $this->assertSame(1, (int) $oneVsFour->group_match);
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
