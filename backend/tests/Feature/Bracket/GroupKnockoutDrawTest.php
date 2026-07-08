<?php

namespace Tests\Feature\Bracket;

use App\Enums\GameStatus;
use App\Support\Bracket\BracketSupport;
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

    public function test_creates_two_group_q3_bracket_with_padding_byes_for_six_qualifiers(): void
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

        $response = $context->createBracket($competition);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 8)
            ->assertJsonPath('data.byes_count', 2)
            ->assertJsonCount(4, 'data.games');

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $firstRound = $context->bracketGamesForRound($bracket, 1);

        $this->assertCount(4, $firstRound);
        $this->assertSame('Cuartos de final', $firstRound[0]->round);
        $this->assertNotSame(BracketSupport::PLAY_IN_ROUND_LABEL, $firstRound[0]->round);

        $byeGames = $firstRound->filter(fn (Game $game): bool => $game->is_bye)->values();

        $this->assertCount(2, $byeGames);
        $this->assertContains($groupAFirst->id, $byeGames->pluck('player1_id')->all());
        $this->assertContains($groupBFirst->id, $byeGames->pluck('player1_id')->all());
    }

    public function test_two_group_q3_generates_semifinals_after_real_games_finished(): void
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

        $response = $context->createBracket($competition);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 8)
            ->assertJsonPath('data.byes_count', 2)
            ->assertJsonCount(4, 'data.games');

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $firstRound = $context->bracketGamesForRound($bracket, 1)->sortBy('bracket_match')->values();

        $this->assertCount(4, $firstRound);
        $this->assertSame('Cuartos de final', $firstRound[0]->round);

        $byeGames = $firstRound->filter(fn (Game $game): bool => $game->is_bye)->values();
        $realGames = $firstRound->reject(fn (Game $game): bool => $game->is_bye)->values();

        $this->assertCount(2, $byeGames);
        $this->assertCount(2, $realGames);

        foreach ($byeGames as $byeGame) {
            $this->assertSame(GameStatus::Finished, $byeGame->status);
            $this->assertSame($byeGame->player1_id, $byeGame->winner_id);
            $this->assertNull($byeGame->player2_id);
        }

        $this->assertContains($groupAFirst->id, $byeGames->pluck('player1_id')->all());
        $this->assertContains($groupBFirst->id, $byeGames->pluck('player1_id')->all());

        foreach ($realGames as $realGame) {
            $this->assertSame(GameStatus::Pending, $realGame->status);
            $this->assertNull($realGame->winner_id);
        }

        $a2VsB3 = $realGames->first(
            fn (Game $game): bool => (
                (int) $game->player1_id === $groupASecond->id
                && (int) $game->player2_id === $groupBThird->id
            ) || (
                (int) $game->player1_id === $groupBThird->id
                && (int) $game->player2_id === $groupASecond->id
            ),
        );
        $b2VsA3 = $realGames->first(
            fn (Game $game): bool => (
                (int) $game->player1_id === $groupBSecond->id
                && (int) $game->player2_id === $groupAThird->id
            ) || (
                (int) $game->player1_id === $groupAThird->id
                && (int) $game->player2_id === $groupBSecond->id
            ),
        );

        $this->assertNotNull($a2VsB3);
        $this->assertNotNull($b2VsA3);

        $context->finishGame($a2VsB3, $groupASecond)->assertOk();
        $context->finishGame($b2VsA3, $groupBSecond)->assertOk();

        $response = $context->generateBracketNextRound($bracket);

        $response->assertCreated();

        $semifinals = $context->bracketGamesForRound($bracket->fresh(), 2)->sortBy('bracket_match')->values();

        $this->assertCount(2, $semifinals);
        $this->assertSame('Semifinal', $semifinals[0]->round);
        $this->assertSame('Semifinal', $semifinals[1]->round);

        $semifinalPlayerIds = $semifinals
            ->flatMap(fn (Game $game): array => [(int) $game->player1_id, (int) $game->player2_id])
            ->all();

        $this->assertContains($groupAFirst->id, $semifinalPlayerIds);
        $this->assertContains($groupBFirst->id, $semifinalPlayerIds);
        $this->assertContains($groupASecond->id, $semifinalPlayerIds);
        $this->assertContains($groupBSecond->id, $semifinalPlayerIds);

        foreach ($semifinals as $semifinal) {
            $this->assertFalse($semifinal->is_bye);
            $this->assertSame(GameStatus::Pending, $semifinal->status);
            $this->assertNull($semifinal->winner_id);
        }

        $this->assertSame($groupAFirst->id, $semifinals[0]->player1_id);
        $this->assertSame($groupBFirst->id, $semifinals[0]->player2_id);
        $this->assertSame($groupASecond->id, $semifinals[1]->player1_id);
        $this->assertSame($groupBSecond->id, $semifinals[1]->player2_id);
    }

    public function test_creates_group_knockout_q3_bracket_with_play_in_and_byes(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFourGroupThreeQualifierPhase($context);

        $response = $context->createBracket($setup['competition']);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 16)
            ->assertJsonPath('data.byes_count', 4)
            ->assertJsonCount(8, 'data.games');

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $firstRound = $context->bracketGamesForRound($bracket, 1);

        $this->assertCount(8, $firstRound);
        $this->assertSame(BracketSupport::PLAY_IN_ROUND_LABEL, $firstRound[0]->round);

        $byeGames = $firstRound->filter(fn (Game $game): bool => $game->is_bye)->values();
        $playInGames = $firstRound->reject(fn (Game $game): bool => $game->is_bye)->values();

        $this->assertCount(4, $byeGames);
        $this->assertCount(4, $playInGames);

        $firstPlaceIds = [
            $setup['groupAFirst']->id,
            $setup['groupBFirst']->id,
            $setup['groupCFirst']->id,
            $setup['groupDFirst']->id,
        ];

        foreach ($byeGames as $byeGame) {
            $this->assertContains($byeGame->player1_id, $firstPlaceIds);
            $this->assertNull($byeGame->player2_id);
            $this->assertSame($byeGame->player1_id, $byeGame->winner_id);
        }

        $groupByPlayerId = $this->groupByPlayerIdFromSetup($setup);

        foreach ($playInGames as $playInGame) {
            $this->assertSame(2, $groupByPlayerId[$playInGame->player1_id]['position']);
            $this->assertSame(3, $groupByPlayerId[$playInGame->player2_id]['position']);
            $this->assertNotSame(
                $groupByPlayerId[$playInGame->player1_id]['groupId'],
                $groupByPlayerId[$playInGame->player2_id]['groupId'],
            );
        }

        for ($pairIndex = 0; $pairIndex < 4; $pairIndex++) {
            $byeGame = $firstRound->firstWhere('bracket_match', ($pairIndex * 2) + 1);
            $playInGame = $firstRound->firstWhere('bracket_match', ($pairIndex * 2) + 2);

            $this->assertNotNull($byeGame);
            $this->assertNotNull($playInGame);

            $firstGroupId = $groupByPlayerId[$byeGame->player1_id]['groupId'];
            $playInGroupIds = [
                $groupByPlayerId[$playInGame->player1_id]['groupId'],
                $groupByPlayerId[$playInGame->player2_id]['groupId'],
            ];

            $this->assertNotContains($firstGroupId, $playInGroupIds);
        }
    }

    public function test_can_advance_from_q3_play_in_to_main_round(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFourGroupThreeQualifierPhase($context);

        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $firstRound = $context->bracketGamesForRound($bracket, 1);
        $groupByPlayerId = $this->groupByPlayerIdFromSetup($setup);

        foreach ($firstRound->reject(fn (Game $game): bool => $game->is_bye) as $playInGame) {
            $context->finishGame($playInGame, $playInGame->player1)->assertOk();
        }

        $response = $context->generateBracketNextRound($bracket);
        $response->assertCreated();

        $secondRound = $context->bracketGamesForRound($bracket, 2);

        $this->assertCount(4, $secondRound);
        $this->assertSame('Cuartos de final', $secondRound[0]->round);

        foreach ($secondRound as $game) {
            $this->assertNotSame(
                $groupByPlayerId[$game->player1_id]['groupId'],
                $groupByPlayerId[$game->player2_id]['groupId'],
            );
        }

        $firstPlaceIds = [
            $setup['groupAFirst']->id,
            $setup['groupBFirst']->id,
            $setup['groupCFirst']->id,
            $setup['groupDFirst']->id,
        ];

        foreach ($secondRound as $game) {
            $this->assertTrue(
                in_array($game->player1_id, $firstPlaceIds, true)
                || in_array($game->player2_id, $firstPlaceIds, true),
            );
        }
    }

    public function test_q3_does_not_assign_bye_to_second_place_even_if_better_global_record(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFourGroupThreeQualifierPhase($context);

        $response = $context->createBracket($setup['competition']);
        $response->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $byeGames = $context->bracketGamesForRound($bracket, 1)
            ->filter(fn (Game $game): bool => $game->is_bye)
            ->values();

        $secondPlaceIds = [
            $setup['groupASecond']->id,
            $setup['groupBSecond']->id,
            $setup['groupCSecond']->id,
            $setup['groupDSecond']->id,
        ];

        foreach ($byeGames as $byeGame) {
            $this->assertNotContains($byeGame->player1_id, $secondPlaceIds);
            $this->assertNotContains($byeGame->winner_id, $secondPlaceIds);
        }
    }

    public function test_direct_knockout_keeps_existing_behavior_after_q3_draw_change(): void
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

    /**
     * @return array{
     *     competition: \App\Models\Competition,
     *     groupA: \App\Models\Group,
     *     groupB: \App\Models\Group,
     *     groupC: \App\Models\Group,
     *     groupD: \App\Models\Group,
     *     groupAFirst: Player,
     *     groupASecond: Player,
     *     groupAThird: Player,
     *     groupBFirst: Player,
     *     groupBSecond: Player,
     *     groupBThird: Player,
     *     groupCFirst: Player,
     *     groupCSecond: Player,
     *     groupCThird: Player,
     *     groupDFirst: Player,
     *     groupDSecond: Player,
     *     groupDThird: Player,
     * }
     */
    private function createFourGroupThreeQualifierPhase(TournamentTestContext $context): array
    {
        $competition = $context->createCompetition();
        $players = $context->createPlayers(12);
        $context->registerPlayers($competition, $players);
        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        [
            $groupAFirst, $groupASecond, $groupAThird,
            $groupBFirst, $groupBSecond, $groupBThird,
            $groupCFirst, $groupCSecond, $groupCThird,
            $groupDFirst, $groupDSecond, $groupDThird,
        ] = $players;

        $groupA = $context->createGroupWithPlayers($competition, [$groupAFirst, $groupASecond, $groupAThird], 'Grupo A');
        $groupB = $context->createGroupWithPlayers($competition, [$groupBFirst, $groupBSecond, $groupBThird], 'Grupo B');
        $groupC = $context->createGroupWithPlayers($competition, [$groupCFirst, $groupCSecond, $groupCThird], 'Grupo C');
        $groupD = $context->createGroupWithPlayers($competition, [$groupDFirst, $groupDSecond, $groupDThird], 'Grupo D');

        foreach ([
            [$groupA, [$groupAFirst, $groupASecond, $groupAThird]],
            [$groupB, [$groupBFirst, $groupBSecond, $groupBThird]],
            [$groupC, [$groupCFirst, $groupCSecond, $groupCThird]],
            [$groupD, [$groupDFirst, $groupDSecond, $groupDThird]],
        ] as [$group, $rankOrder]) {
            $context->generateRoundRobin($group)->assertCreated();
            $this->finishGroupRoundRobinWithRankOrder($context, $group->id, $rankOrder);
        }

        return [
            'competition' => $competition,
            'groupA' => $groupA,
            'groupB' => $groupB,
            'groupC' => $groupC,
            'groupD' => $groupD,
            'groupAFirst' => $groupAFirst,
            'groupASecond' => $groupASecond,
            'groupAThird' => $groupAThird,
            'groupBFirst' => $groupBFirst,
            'groupBSecond' => $groupBSecond,
            'groupBThird' => $groupBThird,
            'groupCFirst' => $groupCFirst,
            'groupCSecond' => $groupCSecond,
            'groupCThird' => $groupCThird,
            'groupDFirst' => $groupDFirst,
            'groupDSecond' => $groupDSecond,
            'groupDThird' => $groupDThird,
        ];
    }

    /**
     * @param  array<string, mixed>  $setup
     * @return array<int, array{groupId: int, position: int}>
     */
    private function groupByPlayerIdFromSetup(array $setup): array
    {
        return [
            $setup['groupAFirst']->id => ['groupId' => $setup['groupA']->id, 'position' => 1],
            $setup['groupASecond']->id => ['groupId' => $setup['groupA']->id, 'position' => 2],
            $setup['groupAThird']->id => ['groupId' => $setup['groupA']->id, 'position' => 3],
            $setup['groupBFirst']->id => ['groupId' => $setup['groupB']->id, 'position' => 1],
            $setup['groupBSecond']->id => ['groupId' => $setup['groupB']->id, 'position' => 2],
            $setup['groupBThird']->id => ['groupId' => $setup['groupB']->id, 'position' => 3],
            $setup['groupCFirst']->id => ['groupId' => $setup['groupC']->id, 'position' => 1],
            $setup['groupCSecond']->id => ['groupId' => $setup['groupC']->id, 'position' => 2],
            $setup['groupCThird']->id => ['groupId' => $setup['groupC']->id, 'position' => 3],
            $setup['groupDFirst']->id => ['groupId' => $setup['groupD']->id, 'position' => 1],
            $setup['groupDSecond']->id => ['groupId' => $setup['groupD']->id, 'position' => 2],
            $setup['groupDThird']->id => ['groupId' => $setup['groupD']->id, 'position' => 3],
        ];
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
