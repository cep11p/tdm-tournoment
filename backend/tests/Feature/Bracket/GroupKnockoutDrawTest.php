<?php

namespace Tests\Feature\Bracket;

use App\Models\Bracket;
use App\Models\Game;
use App\Models\Player;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class GroupKnockoutDrawTest extends TestCase
{
    public function test_builds_two_group_draw_as_a1_vs_b2_and_b1_vs_a2(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $response = $context->createBracket($setup['competition']);

        $response->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $this->assertCount(2, $semifinals);
        $this->assertSame('Semifinal', $semifinals[0]->round);

        $this->assertSame($setup['playerOne']->id, $semifinals[0]->player1_id);
        $this->assertSame($setup['playerFour']->id, $semifinals[0]->player2_id);
        $this->assertSame($setup['playerThree']->id, $semifinals[1]->player1_id);
        $this->assertSame($setup['playerTwo']->id, $semifinals[1]->player2_id);
    }

    public function test_builds_four_group_draw_without_same_group_first_round_matches(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFourGroupPhase($context);

        $response = $context->createBracket($setup['competition']);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 8)
            ->assertJsonPath('data.byes_count', 0);

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $quarterfinals = $context->bracketGamesForRound($bracket, 1);

        $this->assertCount(4, $quarterfinals);
        $this->assertSame('Cuartos de final', $quarterfinals[0]->round);

        $groupByPlayerId = [
            $setup['groupAFirst']->id => $setup['groupA']->id,
            $setup['groupASecond']->id => $setup['groupA']->id,
            $setup['groupBFirst']->id => $setup['groupB']->id,
            $setup['groupBSecond']->id => $setup['groupB']->id,
            $setup['groupCFirst']->id => $setup['groupC']->id,
            $setup['groupCSecond']->id => $setup['groupC']->id,
            $setup['groupDFirst']->id => $setup['groupD']->id,
            $setup['groupDSecond']->id => $setup['groupD']->id,
        ];

        foreach ($quarterfinals as $game) {
            $this->assertNotSame(
                $groupByPlayerId[$game->player1_id],
                $groupByPlayerId[$game->player2_id],
            );
        }

        $this->assertSame($setup['groupAFirst']->id, $quarterfinals[0]->player1_id);
        $this->assertSame($setup['groupDSecond']->id, $quarterfinals[0]->player2_id);
        $this->assertSame($setup['groupBFirst']->id, $quarterfinals[1]->player1_id);
        $this->assertSame($setup['groupCSecond']->id, $quarterfinals[1]->player2_id);
        $this->assertSame($setup['groupCFirst']->id, $quarterfinals[2]->player1_id);
        $this->assertSame($setup['groupBSecond']->id, $quarterfinals[2]->player2_id);
        $this->assertSame($setup['groupDFirst']->id, $quarterfinals[3]->player1_id);
        $this->assertSame($setup['groupASecond']->id, $quarterfinals[3]->player2_id);
    }

    public function test_group_knockout_q2_does_not_use_global_record_for_byes_or_seeding(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(5);
        $context->registerPlayers($competition, $players);

        [$groupAFirst, $groupASecond, $groupAThird, $groupBFirst, $groupBSecond] = $players;

        $groupA = $context->createGroupWithPlayers(
            $competition,
            [$groupAFirst, $groupASecond, $groupAThird],
            'Grupo A',
        );
        $groupB = $context->createGroupWithPlayers(
            $competition,
            [$groupBFirst, $groupBSecond],
            'Grupo B',
        );

        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();

        $this->finishGroupRoundRobinWithRankOrder($context, $groupA->id, [
            $groupAFirst,
            $groupASecond,
            $groupAThird,
        ]);
        $this->finishGroupRoundRobinWithRankOrder($context, $groupB->id, [
            $groupBSecond,
            $groupBFirst,
        ]);

        $response = $context->createBracket($competition);
        $response->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $this->assertSame($groupAFirst->id, $semifinals[0]->player1_id);
        $this->assertSame($groupBFirst->id, $semifinals[0]->player2_id);
        $this->assertSame($groupBSecond->id, $semifinals[1]->player1_id);
        $this->assertSame($groupASecond->id, $semifinals[1]->player2_id);
    }

    public function test_rejects_q2_draw_when_total_qualifiers_is_not_power_of_two(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $groups = [
            $context->createGroupWithPlayers($competition, array_slice($players, 0, 2), 'Grupo A'),
            $context->createGroupWithPlayers($competition, array_slice($players, 2, 2), 'Grupo B'),
            $context->createGroupWithPlayers($competition, array_slice($players, 4, 2), 'Grupo C'),
        ];

        foreach ($groups as $index => $group) {
            $context->generateRoundRobin($group)->assertCreated();

            $game = Game::query()->where('group_id', $group->id)->sole();
            $context->finishGame($game, $players[$index * 2])->assertOk();
        }

        $response = $context->createBracket($competition);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['qualified_per_group']);

        $this->assertDatabaseCount('brackets', 0);
    }

    public function test_direct_knockout_keeps_existing_legacy_behavior(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1)->sortBy('bracket_match')->values();

        $this->assertSame($players[0]->id, $semifinals[0]->player1_id);
        $this->assertSame($players[3]->id, $semifinals[0]->player2_id);
        $this->assertSame($players[1]->id, $semifinals[1]->player1_id);
        $this->assertSame($players[2]->id, $semifinals[1]->player2_id);
    }

    public function test_can_advance_round_after_group_aware_q2_draw(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();
        $context->finishGame($semifinals[1], $setup['playerThree'])->assertOk();

        $response = $context->generateBracketNextRound($bracket);

        $response->assertCreated();

        $final = $context->bracketGamesForRound($bracket, 2);

        $this->assertCount(1, $final);
        $this->assertSame('Final', $final[0]->round);
        $this->assertSame($setup['playerOne']->id, $final[0]->player1_id);
        $this->assertSame($setup['playerThree']->id, $final[0]->player2_id);
    }

    /**
     * @return array{
     *     competition: \App\Models\Competition,
     *     groupA: \App\Models\Group,
     *     groupB: \App\Models\Group,
     *     groupC: \App\Models\Group,
     *     groupD: \App\Models\Group,
     *     groupAFirst: Player,
     *     groupASecond: Player,
     *     groupBFirst: Player,
     *     groupBSecond: Player,
     *     groupCFirst: Player,
     *     groupCSecond: Player,
     *     groupDFirst: Player,
     *     groupDSecond: Player,
     * }
     */
    private function createFourGroupPhase(TournamentTestContext $context): array
    {
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);

        [
            $groupAFirst,
            $groupASecond,
            $groupBFirst,
            $groupBSecond,
            $groupCFirst,
            $groupCSecond,
            $groupDFirst,
            $groupDSecond,
        ] = $players;

        $groupA = $context->createGroupWithPlayers($competition, [$groupAFirst, $groupASecond], 'Grupo A');
        $groupB = $context->createGroupWithPlayers($competition, [$groupBFirst, $groupBSecond], 'Grupo B');
        $groupC = $context->createGroupWithPlayers($competition, [$groupCFirst, $groupCSecond], 'Grupo C');
        $groupD = $context->createGroupWithPlayers($competition, [$groupDFirst, $groupDSecond], 'Grupo D');

        foreach ([
            [$groupA, $groupAFirst],
            [$groupB, $groupBFirst],
            [$groupC, $groupCFirst],
            [$groupD, $groupDFirst],
        ] as [$group, $winner]) {
            $context->generateRoundRobin($group)->assertCreated();

            $game = Game::query()->where('group_id', $group->id)->sole();
            $context->finishGame($game, $winner)->assertOk();
        }

        return [
            'competition' => $competition,
            'groupA' => $groupA,
            'groupB' => $groupB,
            'groupC' => $groupC,
            'groupD' => $groupD,
            'groupAFirst' => $groupAFirst,
            'groupASecond' => $groupASecond,
            'groupBFirst' => $groupBFirst,
            'groupBSecond' => $groupBSecond,
            'groupCFirst' => $groupCFirst,
            'groupCSecond' => $groupCSecond,
            'groupDFirst' => $groupDFirst,
            'groupDSecond' => $groupDSecond,
        ];
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
                        (int) $candidate->player1_id === $left->id && (int) $candidate->player2_id === $right->id
                    ) || (
                        (int) $candidate->player1_id === $right->id && (int) $candidate->player2_id === $left->id
                    )
                );

                $this->assertNotNull($game);
                $context->finishGame($game, $winner)->assertOk();
            }
        }
    }
}
