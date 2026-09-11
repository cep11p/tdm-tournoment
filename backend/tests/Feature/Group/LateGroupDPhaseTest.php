<?php

namespace Tests\Feature\Group;

use App\Actions\Group\PersistGroupEntryAction;
use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Enums\TournamentStatus;
use App\Models\Bracket;
use App\Models\BracketEntryOrigin;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Group;
use App\Support\Bracket\GroupKnockoutDrawBuilder;
use App\Support\Bracket\GroupQualifiersCollector;
use App\Support\Competition\LateGroupMutationGuard;
use App\Support\Tournament\TournamentLifecycleGuard;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class LateGroupDPhaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_finished_groups_without_late_group_are_ready_for_bracket(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGroups($context, ['Grupo A', 'Grupo B', 'Grupo C'], playersPerGroup: 2);

        $this->assertStatusCode($setup['competition']->id, 'ready_for_bracket');
    }

    public function test_creating_empty_group_d_leaves_group_stage_pending(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGroups($context, ['Grupo A', 'Grupo B', 'Grupo C'], playersPerGroup: 2);

        $context->createGroupViaApi($setup['competition'], 'Grupo D')->assertCreated();

        $this->assertStatusCode($setup['competition']->id, 'group_stage_pending');
        $this->assertSame(
            1,
            Activity::query()->where('description', AuditAction::GROUP_CREATED->value)->where('subject_id', $this->groupByName($setup['competition']->id, 'Grupo D')->id)->count(),
        );
    }

    public function test_group_d_with_one_entry_is_not_ready_for_bracket(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGroups($context, ['Grupo A', 'Grupo B', 'Grupo C'], playersPerGroup: 2);
        $groupD = $this->createLateGroup($context, $setup['competition'], 'Grupo D');
        [$latePlayer] = $context->createPlayers(1);
        $context->registerPlayerViaApi($setup['competition'], $latePlayer)->assertCreated();

        $context->assignPlayerToGroupViaApi($groupD, $latePlayer)->assertCreated();

        $this->assertSame(1, $groupD->groupEntries()->count());
        $this->assertSame(0, $groupD->games()->count());
        $this->assertStatusCode($setup['competition']->id, 'group_stage_pending');
    }

    public function test_group_d_with_pending_g2_is_group_stage_in_progress(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGroups($context, ['Grupo A', 'Grupo B', 'Grupo C'], playersPerGroup: 2);
        $groupD = $this->createLateGroup($context, $setup['competition'], 'Grupo D');
        $players = $context->createPlayers(2);
        $context->registerPlayers($setup['competition'], $players);

        $context->assignPlayerToGroupViaApi($groupD, $players[0])->assertCreated();
        $context->assignPlayerToGroupViaApi($groupD, $players[1])->assertCreated();

        $this->assertSame(1, $groupD->games()->count());
        $this->assertStatusCode($setup['competition']->id, 'group_stage_in_progress');
    }

    public function test_partially_played_group_d_keeps_group_stage_in_progress(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGroups($context, ['Grupo A', 'Grupo B', 'Grupo C'], playersPerGroup: 2);
        $groupD = $this->createLateGroup($context, $setup['competition'], 'Grupo D');
        $players = $context->createPlayers(3);
        $context->registerPlayers($setup['competition'], $players);

        foreach ($players as $player) {
            $context->assignPlayerToGroupViaApi($groupD, $player)->assertCreated();
        }

        $firstGame = $groupD->games()->orderBy('id')->firstOrFail();
        $context->finishGame($firstGame, $firstGame->singlesPlayer1())->assertOk();

        $this->assertSame(3, $groupD->games()->count());
        $this->assertStatusCode($setup['competition']->id, 'group_stage_in_progress');
    }

    public function test_finished_group_d_returns_ready_for_bracket(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGroups($context, ['Grupo A', 'Grupo B', 'Grupo C'], playersPerGroup: 2);
        $groupD = $this->createLateGroup($context, $setup['competition'], 'Grupo D');
        $players = $context->createPlayers(2);
        $context->registerPlayers($setup['competition'], $players);

        foreach ($players as $player) {
            $context->assignPlayerToGroupViaApi($groupD, $player)->assertCreated();
        }

        $this->finishAllGroupGames($context, $groupD);

        $this->assertStatusCode($setup['competition']->id, 'ready_for_bracket');
    }

    public function test_incomplete_fixture_on_group_d_is_not_ready_and_blocks_bracket(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGroups($context, ['Grupo A', 'Grupo B'], playersPerGroup: 2);
        $players = $context->createPlayers(4);
        $entries = [];
        foreach ($players as $player) {
            $entries[] = $context->registerPlayer($setup['competition'], $player);
        }
        $groupD = $context->createGroupWithEntries($setup['competition'], array_slice($entries, 0, 3), 'Grupo D');
        $context->generateRoundRobin($groupD)->assertCreated();
        $this->finishAllGroupGames($context, $groupD);
        app(PersistGroupEntryAction::class)($groupD, $entries[3]);

        $this->assertSame(4, $groupD->groupEntries()->count());
        $this->assertSame(3, $groupD->games()->count());
        $this->assertStatusCode($setup['competition']->id, 'group_stage_pending');

        $context->createBracket($setup['competition'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group'])
            ->assertJsonPath('errors.group.0', 'El grupo "Grupo D" no tiene el round-robin completo.');
    }

    public function test_pending_games_on_group_d_block_bracket(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGroups($context, ['Grupo A', 'Grupo B'], playersPerGroup: 2);
        $groupD = $this->createLateGroup($context, $setup['competition'], 'Grupo D');
        $players = $context->createPlayers(2);
        $context->registerPlayers($setup['competition'], $players);
        foreach ($players as $player) {
            $context->assignPlayerToGroupViaApi($groupD, $player)->assertCreated();
        }

        $context->createBracket($setup['competition'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group'])
            ->assertJsonPath('errors.group.0', 'El grupo "Grupo D" todavía tiene partidos sin finalizar.');
    }

    public function test_group_d_with_fewer_entries_than_qualified_per_group_blocks_bracket(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGroups($context, ['Grupo A', 'Grupo B', 'Grupo C'], playersPerGroup: 2);
        $groupD = $this->createLateGroup($context, $setup['competition'], 'Grupo D');
        [$latePlayer] = $context->createPlayers(1);
        $context->registerPlayerViaApi($setup['competition'], $latePlayer)->assertCreated();
        $context->assignPlayerToGroupViaApi($groupD, $latePlayer)->assertCreated();

        $context->createBracket($setup['competition'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group'])
            ->assertJsonPath('errors.group.0', 'El grupo "Grupo D" necesita al menos 2 jugadores.');
    }

    public function test_empty_group_d_blocks_bracket_with_clear_message(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGroups($context, ['Grupo A', 'Grupo B', 'Grupo C'], playersPerGroup: 2);
        $context->createGroupViaApi($setup['competition'], 'Grupo D')->assertCreated();

        $context->createBracket($setup['competition'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group'])
            ->assertJsonPath('errors.group.0', 'El grupo "Grupo D" necesita al menos 2 jugadores.');
    }

    public function test_three_groups_q2_cannot_build_draw_until_group_d_exists(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGroups($context, ['Grupo A', 'Grupo B', 'Grupo C'], playersPerGroup: 2);

        $context->createBracket($setup['competition'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['qualified_per_group'])
            ->assertJsonPath(
                'errors.qualified_per_group.0',
                'El cuadro eliminatorio requiere una cantidad de grupos potencia de 2 (actual: 3).',
            );

        $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/groups"))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_manual_tiebreak_on_late_group_keeps_attention_required(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition(setsToWin: 3);
        foreach (['Grupo A', 'Grupo B'] as $name) {
            $players = $context->createPlayers(2);
            $context->registerPlayers($competition, $players);
            $group = $context->createGroupWithPlayers($competition, $players, $name);
            $context->generateRoundRobin($group)->assertCreated();
            $this->finishAllGroupGames($context, $group);
        }

        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $groupD = $context->createGroupWithPlayers($competition, $players, 'Grupo D');
        $context->generateRoundRobin($groupD)->assertCreated();

        $games = $groupD->games()->get();
        $balancedSets = [
            [11, 9],
            [11, 9],
            [9, 11],
            [11, 9],
        ];
        $this->playBalancedMatch($context, $context->findGameBetween($games, $players[0], $players[1]), $players[0], $players[1], $balancedSets);
        $this->playBalancedMatch($context, $context->findGameBetween($games, $players[1], $players[2]), $players[1], $players[2], $balancedSets);
        $this->playBalancedMatch($context, $context->findGameBetween($games, $players[2], $players[0]), $players[2], $players[0], $balancedSets);

        $competition->update(['qualified_per_group' => 2]);
        $competition->refresh();

        $this->assertStatusCode($competition->id, 'group_stage_attention_required');
    }

    public function test_print_all_groups_fails_while_d_is_empty_and_succeeds_when_complete(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGroups($context, ['Grupo A', 'Grupo B'], playersPerGroup: 4);
        $groupA = $this->groupByName($setup['competition']->id, 'Grupo A');

        $this->getJson($context->apiUrl("groups/{$groupA->id}/print"))->assertOk();

        $context->createGroupViaApi($setup['competition'], 'Grupo D')->assertCreated();

        $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/groups/print"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);

        $groupD = $this->groupByName($setup['competition']->id, 'Grupo D');
        $players = $context->createPlayers(4);
        $context->registerPlayers($setup['competition'], $players);
        foreach ($players as $player) {
            $context->assignPlayerToGroupViaApi($groupD, $player)->assertCreated();
        }

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/groups/print"));
        $response
            ->assertOk()
            ->assertJsonPath('data.groups_count', 3)
            ->assertJsonCount(3, 'data.sheets');

        $this->assertSame(
            ['Grupo A', 'Grupo B', 'Grupo D'],
            collect($response->json('data.sheets'))->pluck('group.name')->all(),
        );
    }

    public function test_bracket_blocks_creating_another_late_group(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGroups($context, ['Grupo A', 'Grupo B'], playersPerGroup: 2);
        $context->createBracket($setup['competition'])->assertCreated();

        $context->createGroupViaApi($setup['competition'], 'Grupo E')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', LateGroupMutationGuard::LOCK_MESSAGE);
    }

    public function test_finished_tournament_blocks_creating_group_d(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGroups($context, ['Grupo A', 'Grupo B'], playersPerGroup: 2);
        $setup['competition']->tournament->update([
            'status' => TournamentStatus::Finished,
            'closed_at' => now(),
        ]);

        $context->createGroupViaApi($setup['competition'], 'Grupo D')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament'])
            ->assertJsonPath('errors.tournament.0', TournamentLifecycleGuard::LOCK_MESSAGE);
    }

    public function test_team_status_still_depends_on_team_ties_not_empty_games(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();

        $this->assertStatusCode($competition->id, 'group_stage_in_progress');
    }

    public function test_end_to_end_late_group_d_g4_enters_bracket_with_origins(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGroups($context, ['Grupo A', 'Grupo B', 'Grupo C'], playersPerGroup: 4);
        $competition = $setup['competition'];
        $abcSnapshot = $this->snapshotGroups($setup['groups']);

        $this->assertStatusCode($competition->id, 'ready_for_bracket');

        $context->createBracket($competition)
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.qualified_per_group.0',
                'El cuadro eliminatorio requiere una cantidad de grupos potencia de 2 (actual: 3).',
            );

        $latePlayers = $context->createPlayers(4);
        foreach ($latePlayers as $player) {
            $context->registerPlayerViaApi($competition, $player)->assertCreated();
        }

        $groupDId = (int) $context->createGroupViaApi($competition, 'Grupo D')->assertCreated()->json('data.id');
        $groupD = Group::query()->findOrFail($groupDId);

        $this->assertStatusCode($competition->id, 'group_stage_pending');
        $this->assertSame(4, $competition->groups()->count());

        $context->assignPlayerToGroupViaApi($groupD, $latePlayers[0])->assertCreated();
        $this->assertSame(0, $groupD->games()->count());
        $this->assertStatusCode($competition->id, 'group_stage_pending');

        $context->assignPlayerToGroupViaApi($groupD, $latePlayers[1])->assertCreated();
        $this->assertSame(1, $groupD->games()->count());
        $this->assertStatusCode($competition->id, 'group_stage_in_progress');

        $context->assignPlayerToGroupViaApi($groupD, $latePlayers[2])->assertCreated();
        $this->assertSame(3, $groupD->games()->count());

        $context->assignPlayerToGroupViaApi($groupD, $latePlayers[3])->assertCreated();
        $this->assertSame(6, $groupD->games()->count());
        $this->assertStatusCode($competition->id, 'group_stage_in_progress');

        $this->finishAllGroupGames($context, $groupD);
        $this->assertStatusCode($competition->id, 'ready_for_bracket');

        $this->assertSame($abcSnapshot, $this->snapshotGroups($setup['groups']));

        $qualifiers = app(GroupQualifiersCollector::class)->collect($competition->fresh());
        $this->assertCount(8, $qualifiers);

        $draw = app(GroupKnockoutDrawBuilder::class)->build($qualifiers, 2);
        $this->assertCount(8, $draw);

        $dQualifiers = $qualifiers
            ->filter(fn ($qualifier): bool => $qualifier->groupId === $groupD->id)
            ->sortBy('groupPosition')
            ->values();
        $this->assertCount(2, $dQualifiers);
        $this->assertSame('Grupo D', $dQualifiers[0]->groupName);
        $this->assertSame(1, $dQualifiers[0]->groupPosition);
        $this->assertSame(2, $dQualifiers[1]->groupPosition);

        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $dOrigins = BracketEntryOrigin::query()
            ->where('bracket_id', $bracket->id)
            ->where('group_id', $groupD->id)
            ->orderBy('group_position')
            ->get();

        $this->assertCount(2, $dOrigins);
        $this->assertSame('Grupo D', $dOrigins[0]->group_name);
        $this->assertSame(1, (int) $dOrigins[0]->group_position);
        $this->assertSame($dQualifiers[0]->competitionEntryId, (int) $dOrigins[0]->competition_entry_id);
        $this->assertSame($competition->id, (int) $dOrigins[0]->competition_id);
        $this->assertSame($bracket->id, (int) $dOrigins[0]->bracket_id);
        $this->assertSame('Grupo D', $dOrigins[1]->group_name);
        $this->assertSame(2, (int) $dOrigins[1]->group_position);
        $this->assertSame($dQualifiers[1]->competitionEntryId, (int) $dOrigins[1]->competition_entry_id);

        $originGroupNames = BracketEntryOrigin::query()
            ->where('bracket_id', $bracket->id)
            ->pluck('group_name')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(
            ['Grupo A', 'Grupo A', 'Grupo B', 'Grupo B', 'Grupo C', 'Grupo C', 'Grupo D', 'Grupo D'],
            $originGroupNames,
        );

        $this->getJson($context->apiUrl("groups/{$groupD->id}/print"))
            ->assertOk()
            ->assertJsonPath('data.sheet_kind', 'g4')
            ->assertJsonCount(6, 'data.matches');
    }

    /**
     * @param  list<string>  $names
     * @return array{competition: Competition, groups: list<Group>}
     */
    private function createFinishedGroups(TournamentTestContext $context, array $names, int $playersPerGroup): array
    {
        $competition = $context->createCompetition();
        $groups = [];

        foreach ($names as $name) {
            $players = $context->createPlayers($playersPerGroup);
            $context->registerPlayers($competition, $players);
            $group = $context->createGroupWithPlayers($competition, $players, $name);
            $context->generateRoundRobin($group)->assertCreated();
            $this->finishAllGroupGames($context, $group);
            $groups[] = $group->fresh();
        }

        return [
            'competition' => $competition->fresh(),
            'groups' => $groups,
        ];
    }

    private function createLateGroup(TournamentTestContext $context, $competition, string $name): Group
    {
        $id = (int) $context->createGroupViaApi($competition, $name)->assertCreated()->json('data.id');

        return Group::query()->findOrFail($id);
    }

    private function finishAllGroupGames(TournamentTestContext $context, Group $group): void
    {
        foreach ($group->games()->orderBy('id')->get() as $game) {
            if ($game->status === GameStatus::Finished) {
                continue;
            }

            $context->finishGame($game, $game->singlesPlayer1())->assertOk();
        }
    }

    private function assertStatusCode(int $competitionId, string $code): void
    {
        $this->getJson($this->tournamentContext()->apiUrl("competitions/{$competitionId}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', $code);
    }

    private function groupByName(int $competitionId, string $name): Group
    {
        return Group::query()
            ->where('competition_id', $competitionId)
            ->where('name', $name)
            ->firstOrFail();
    }

    /**
     * @param  list<Group>  $groups
     * @return list<array<string, mixed>>
     */
    private function snapshotGroups(array $groups): array
    {
        $snapshot = [];

        foreach ($groups as $group) {
            $snapshot[] = [
                'id' => (int) $group->id,
                'entry_ids' => $group->groupEntries()->orderBy('id')->pluck('competition_entry_id')->map(fn ($id): int => (int) $id)->all(),
                'games' => $group->games()
                    ->orderBy('id')
                    ->get()
                    ->map(fn (Game $game): array => [
                        'id' => (int) $game->id,
                        'status' => $game->status->value,
                        'winner_entry_id' => $game->winner_entry_id,
                        'entry1_id' => (int) $game->entry1_id,
                        'entry2_id' => (int) $game->entry2_id,
                        'group_round' => $game->group_round,
                        'group_match' => $game->group_match,
                    ])
                    ->all(),
            ];
        }

        return $snapshot;
    }

    /**
     * @param  array<int, array{int, int}>  $sets
     */
    private function playBalancedMatch(
        TournamentTestContext $context,
        Game $game,
        $leftPlayer,
        $rightPlayer,
        array $sets,
    ): void {
        foreach ($sets as $index => [$leftScore, $rightScore]) {
            $player1IsLeft = (int) $game->singlesPlayer1Id() === $leftPlayer->id;
            $player1Score = $player1IsLeft ? $leftScore : $rightScore;
            $player2Score = $player1IsLeft ? $rightScore : $leftScore;

            $context->recordSet(
                $game,
                setNumber: $index + 1,
                player1Score: $player1Score,
                player2Score: $player2Score,
            )->assertOk();
        }
    }
}
