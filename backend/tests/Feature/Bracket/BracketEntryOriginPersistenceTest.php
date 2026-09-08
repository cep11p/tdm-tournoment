<?php

namespace Tests\Feature\Bracket;

use App\Actions\Bracket\CreateBracketEntryOriginsAction;
use App\Data\Competition\GroupQualifierData;
use App\Enums\TeamTieModality;
use App\Models\Bracket;
use App\Models\BracketEntryOrigin;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\Group;
use App\Models\Player;
use App\Models\TeamTie;
use App\Support\Bracket\GroupQualifiersCollector;
use Illuminate\Validation\ValidationException;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class BracketEntryOriginPersistenceTest extends TestCase
{
    public function test_q3_official_template_persists_origin_for_every_qualifier(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createOfficialQ3Phase($context, ['A', 'B', 'C']);
        $expected = app(GroupQualifiersCollector::class)->collect($setup['competition']->fresh());

        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();

        $this->assertOriginsMatchQualifiers($bracket, $expected);
        $this->assertSame(9, $expected->count());
    }

    public function test_bye_and_real_match_sides_both_have_origins(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        $group = $context->createGroupWithPlayers($competition, $players, 'Grupo A');
        $context->generateRoundRobin($group)->assertCreated();
        $this->finishGroupRoundRobinWithRankOrder($context, $group->id, $players);

        $expected = app(GroupQualifiersCollector::class)->collect($competition->fresh());
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $this->assertOriginsMatchQualifiers($bracket, $expected);

        $games = $context->bracketGamesForRound($bracket, 1);
        $byeGame = $games->first(fn (Game $game): bool => $game->is_bye);
        $realGame = $games->first(fn (Game $game): bool => ! $game->is_bye);

        $this->assertNotNull($byeGame);
        $this->assertNotNull($realGame);
        $this->assertNotNull($realGame->entry2_id);

        $this->assertTrue(
            BracketEntryOrigin::query()
                ->where('bracket_id', $bracket->id)
                ->where('competition_entry_id', $byeGame->entry1_id)
                ->exists(),
        );
        $this->assertTrue(
            BracketEntryOrigin::query()
                ->where('bracket_id', $bracket->id)
                ->where('competition_entry_id', $realGame->entry1_id)
                ->exists(),
        );
        $this->assertTrue(
            BracketEntryOrigin::query()
                ->where('bracket_id', $bracket->id)
                ->where('competition_entry_id', $realGame->entry2_id)
                ->exists(),
        );
    }

    public function test_q2_from_groups_persists_origins(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $expected = app(GroupQualifiersCollector::class)->collect($setup['competition']->fresh());

        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $this->assertOriginsMatchQualifiers($bracket, $expected);
        $this->assertSame(4, $expected->count());
    }

    public function test_q1_from_groups_persists_origins_only_for_qualifiers(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $setup['competition']->update(['qualified_per_group' => 1]);
        $setup['competition']->refresh();

        $expected = app(GroupQualifiersCollector::class)->collect($setup['competition']->fresh());
        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $this->assertOriginsMatchQualifiers($bracket, $expected);
        $this->assertSame(2, $expected->count());

        $eliminatedEntryIds = [
            $context->entryIdFor($setup['competition'], $setup['playerTwo']),
            $context->entryIdFor($setup['competition'], $setup['playerFour']),
        ];

        foreach ($eliminatedEntryIds as $eliminatedEntryId) {
            $this->assertFalse(
                BracketEntryOrigin::query()
                    ->where('bracket_id', $bracket->id)
                    ->where('competition_entry_id', $eliminatedEntryId)
                    ->exists(),
            );
        }
    }

    public function test_q3_legacy_two_groups_persists_origins(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(7);
        $context->registerPlayers($competition, $players);
        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        [
            $groupAFirst,
            $groupASecond,
            $groupAThird,
            $groupAFourth,
            $groupBFirst,
            $groupBSecond,
            $groupBThird,
        ] = $players;

        $groupA = $context->createGroupWithPlayers(
            $competition,
            [$groupAFirst, $groupASecond, $groupAThird, $groupAFourth],
            'Grupo A',
        );
        $groupB = $context->createGroupWithPlayers(
            $competition,
            [$groupBFirst, $groupBSecond, $groupBThird],
            'Grupo B',
        );

        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();
        $this->finishGroupRoundRobinWithRankOrder($context, $groupA->id, [
            $groupAFirst,
            $groupASecond,
            $groupAThird,
            $groupAFourth,
        ]);
        $this->finishGroupRoundRobinWithRankOrder($context, $groupB->id, [
            $groupBFirst,
            $groupBSecond,
            $groupBThird,
        ]);

        $expected = app(GroupQualifiersCollector::class)->collect($competition->fresh());
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $this->assertOriginsMatchQualifiers($bracket, $expected);
        $this->assertSame(6, $expected->count());

        $fourthPlaceEntryId = $context->entryIdFor($competition, $groupAFourth);
        $this->assertFalse(
            BracketEntryOrigin::query()
                ->where('bracket_id', $bracket->id)
                ->where('competition_entry_id', $fourthPlaceEntryId)
                ->exists(),
        );
    }

    public function test_knockout_direct_does_not_create_origins(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $context->createBracket($competition)->assertCreated();

        $this->assertDatabaseCount('bracket_entry_origins', 0);
    }

    public function test_never_creates_more_than_one_origin_per_bracket_entry(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $origins = BracketEntryOrigin::query()->where('bracket_id', $bracket->id)->get();

        $this->assertSame(
            $origins->count(),
            $origins->unique(fn (BracketEntryOrigin $origin): string => $origin->bracket_id.'-'.$origin->competition_entry_id)->count(),
        );
        $this->assertSame(
            $origins->count(),
            $origins->pluck('competition_entry_id')->unique()->count(),
        );
    }

    public function test_group_name_snapshot_survives_later_group_rename(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $originalName = (string) $setup['groupA']->name;

        $context->createBracket($setup['competition'])->assertCreated();

        $setup['groupA']->update(['name' => 'Grupo Renombrado']);

        $entryId = $context->entryIdFor($setup['competition'], $setup['playerOne']);
        $origin = BracketEntryOrigin::query()
            ->where('competition_entry_id', $entryId)
            ->sole();

        $this->assertSame($originalName, $origin->group_name);
        $this->assertSame('Grupo A', $origin->group_name);
        $this->assertSame('Grupo Renombrado', $setup['groupA']->fresh()->name);
    }

    public function test_origin_position_matches_eligible_qualifier_position_not_full_table_index(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        [$playerOne, $playerTwo, $playerThree] = $players;

        $group = $context->createGroupWithPlayers($competition, $players, 'Grupo A');
        $context->generateRoundRobin($group)->assertCreated();
        $this->finishGroupRoundRobinWithRankOrder($context, $group->id, $players);

        $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $playerOne->id,
            'status' => 'withdrawn',
        ], $this->authHeaders(['organizer']))->assertCreated();

        $expected = app(GroupQualifiersCollector::class)->collect($competition->fresh());
        $this->assertSame(2, $expected->count());
        $this->assertSame(1, $expected[0]->groupPosition);
        $this->assertSame($playerTwo->id, $expected[0]->playerId);
        $this->assertSame(2, $expected[1]->groupPosition);
        $this->assertSame($playerThree->id, $expected[1]->playerId);

        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $this->assertOriginsMatchQualifiers($bracket, $expected);

        $secondEntryId = $context->entryIdFor($competition, $playerTwo);
        $thirdEntryId = $context->entryIdFor($competition, $playerThree);

        $this->assertSame(
            1,
            (int) BracketEntryOrigin::query()
                ->where('bracket_id', $bracket->id)
                ->where('competition_entry_id', $secondEntryId)
                ->value('group_position'),
        );
        $this->assertSame(
            2,
            (int) BracketEntryOrigin::query()
                ->where('bracket_id', $bracket->id)
                ->where('competition_entry_id', $thirdEntryId)
                ->value('group_position'),
        );
        $this->assertFalse(
            BracketEntryOrigin::query()
                ->where('bracket_id', $bracket->id)
                ->where('competition_entry_id', $context->entryIdFor($competition, $playerOne))
                ->exists(),
        );
    }

    public function test_deleting_bracket_cascades_origins(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $this->assertGreaterThan(0, BracketEntryOrigin::query()->where('bracket_id', $bracket->id)->count());

        $bracket->delete();

        $this->assertDatabaseCount('bracket_entry_origins', 0);
    }

    public function test_team_competition_entry_gets_origin_without_rubber_rows(): void
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

        $competition->update(['qualified_per_group' => 1]);
        $competition->refresh();

        $expected = app(GroupQualifiersCollector::class)->collect($competition->fresh());
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $this->assertOriginsMatchQualifiers($bracket, $expected);
        $this->assertSame(2, $expected->count());

        $rubberCount = Game::query()
            ->where('competition_id', $competition->id)
            ->whereHas('teamTieGame')
            ->count();

        $this->assertGreaterThan(2, $rubberCount);
        $this->assertSame(2, BracketEntryOrigin::query()->where('bracket_id', $bracket->id)->count());
        $this->assertSame(0, Game::query()->whereNotNull('bracket_id')->count());
    }

    public function test_fails_when_used_entry_is_missing_from_qualifiers(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $qualifiers = app(GroupQualifiersCollector::class)->collect($setup['competition']->fresh());
        $existingEntryId = $qualifiers->first()->competitionEntryId;

        $bracket = Bracket::query()->create([
            'competition_id' => $setup['competition']->id,
            'name' => 'Llave test',
            'qualifiers_per_group' => 2,
            'bracket_size' => 2,
            'byes_count' => 0,
        ]);

        try {
            app(CreateBracketEntryOriginsAction::class)(
                $bracket,
                $qualifiers,
                [$existingEntryId, 9_999_999],
            );
            $this->fail('Se esperaba ValidationException al persistir un origin sin clasificado.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bracket', $exception->errors());
            $this->assertStringContainsString(
                'no está entre los clasificados',
                $exception->errors()['bracket'][0],
            );
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, GroupQualifierData>  $expected
     */
    private function assertOriginsMatchQualifiers(Bracket $bracket, $expected): void
    {
        $this->assertSame($expected->count(), BracketEntryOrigin::query()->where('bracket_id', $bracket->id)->count());

        foreach ($expected as $qualifier) {
            $this->assertDatabaseHas('bracket_entry_origins', [
                'bracket_id' => $bracket->id,
                'competition_id' => $bracket->competition_id,
                'competition_entry_id' => $qualifier->competitionEntryId,
                'group_id' => $qualifier->groupId,
                'group_name' => $qualifier->groupName,
                'group_position' => $qualifier->groupPosition,
            ]);
        }
    }

    /**
     * @param  list<string>  $groupLetters
     * @return array{competition: \App\Models\Competition}
     */
    private function createOfficialQ3Phase(TournamentTestContext $context, array $groupLetters): array
    {
        $competition = $context->createCompetition();
        $players = $context->createPlayers(count($groupLetters) * 3);
        $context->registerPlayers($competition, $players);
        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        $playerIndex = 0;

        foreach ($groupLetters as $letter) {
            $groupPlayers = [
                $players[$playerIndex],
                $players[$playerIndex + 1],
                $players[$playerIndex + 2],
            ];
            $playerIndex += 3;

            $group = $context->createGroupWithPlayers(
                $competition,
                $groupPlayers,
                'Grupo '.$letter,
            );
            $context->generateRoundRobin($group)->assertCreated();
            $this->finishGroupRoundRobinWithRankOrder($context, $group->id, $groupPlayers);
        }

        return ['competition' => $competition];
    }

    /**
     * @param  array<int, Player>  $playersInRankOrder
     */
    private function finishGroupRoundRobinWithRankOrder(
        TournamentTestContext $context,
        int $groupId,
        array $playersInRankOrder,
    ): void {
        $games = Game::query()->where('group_id', $groupId)->get();

        for ($index = 0; $index < count($playersInRankOrder); $index++) {
            for ($pairIndex = $index + 1; $pairIndex < count($playersInRankOrder); $pairIndex++) {
                $winner = $playersInRankOrder[$index];
                $left = $playersInRankOrder[$index];
                $right = $playersInRankOrder[$pairIndex];

                $game = $games->first(
                    fn (Game $candidate): bool => (
                        (int) $candidate->singlesPlayer1Id() === $left->id && (int) $candidate->singlesPlayer2Id() === $right->id
                    ) || (
                        (int) $candidate->singlesPlayer1Id() === $right->id && (int) $candidate->singlesPlayer2Id() === $left->id
                    )
                );

                $this->assertNotNull($game);
                $context->finishGame($game, $winner)->assertOk();
            }
        }
    }

    /**
     * @param  list<\App\Models\CompetitionEntry>  $entries
     */
    private function finishGroupWinner(
        TournamentTestContext $context,
        Group $group,
        array $entries,
        \App\Models\CompetitionEntry $winner,
    ): void {
        $teamTies = TeamTie::query()->where('group_id', $group->id)->get();

        foreach ($teamTies as $teamTie) {
            $winnerId = (int) $winner->id === (int) $teamTie->entry1_id
                || (int) $winner->id === (int) $teamTie->entry2_id
                ? (int) $winner->id
                : (int) $teamTie->entry1_id;

            foreach ([1, 2, 3] as $slot) {
                $this->winRubber($context, $teamTie->fresh(), $entries, $slot, $winnerId);
            }
        }
    }

    /**
     * @param  list<\App\Models\CompetitionEntry>  $entries
     */
    private function winRubber(
        TournamentTestContext $context,
        TeamTie $teamTie,
        array $entries,
        int $slotOrder,
        int $winnerEntryId,
    ): void {
        $rubber = $teamTie->teamTieGames()->where('slot_order', $slotOrder)->firstOrFail();
        $entry1 = collect($entries)->firstWhere('id', $teamTie->entry1_id);
        $entry2 = collect($entries)->firstWhere('id', $teamTie->entry2_id);
        $requiredPerSide = $rubber->modality === TeamTieModality::Doubles ? 2 : 1;

        $context->setTeamTieGameLineup($rubber, [
            'entry1_player_ids' => $this->playerIds($entry1, $requiredPerSide),
            'entry2_player_ids' => $this->playerIds($entry2, $requiredPerSide),
        ])->assertOk();

        $context->finishGameByEntryViaApi($rubber->game->fresh(), $winnerEntryId)->assertOk();
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
