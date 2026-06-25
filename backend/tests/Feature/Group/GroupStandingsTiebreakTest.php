<?php

namespace Tests\Feature\Group;

use App\Models\Game;
use App\Models\Player;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class GroupStandingsTiebreakTest extends TestCase
{
    public function test_resolves_double_tie_with_mini_table(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition(setsToWin: 2);
        [$playerA, $playerB, $playerC, $playerD] = $context->createPlayers(4);
        $players = [$playerA, $playerB, $playerC, $playerD];
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()->where('group_id', $group->id)->get();

        $this->playMatch($context, $context->findGameBetween($games, $playerA, $playerB), $playerA, $playerB, [
            [11, 8],
            [11, 9],
        ]);
        $this->playMatch($context, $context->findGameBetween($games, $playerA, $playerC), $playerA, $playerC, [
            [11, 9],
            [11, 6],
        ]);
        $this->playMatch($context, $context->findGameBetween($games, $playerD, $playerA), $playerD, $playerA, [
            [11, 9],
            [11, 8],
        ]);
        $this->playMatch($context, $context->findGameBetween($games, $playerB, $playerC), $playerB, $playerC, [
            [11, 5],
            [11, 7],
        ]);
        $this->playMatch($context, $context->findGameBetween($games, $playerB, $playerD), $playerB, $playerD, [
            [11, 6],
            [11, 7],
        ]);
        $this->playMatch($context, $context->findGameBetween($games, $playerC, $playerD), $playerC, $playerD, [
            [11, 8],
            [11, 9],
        ]);

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('data.0.player_id', $playerA->id)
            ->assertJsonPath('data.1.player_id', $playerB->id)
            ->assertJsonPath('meta.requires_manual_tiebreak', false)
            ->assertJsonPath('meta.manual_tiebreak_groups', []);
    }

    public function test_resolves_triple_tie_with_sets_difference(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition(setsToWin: 3);
        [$playerA, $playerB, $playerC] = $context->createPlayers(3);
        $players = [$playerA, $playerB, $playerC];
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()->where('group_id', $group->id)->get();

        $this->playMatch($context, $context->findGameBetween($games, $playerA, $playerB), $playerA, $playerB, [
            [11, 8],
            [11, 9],
            [11, 5],
        ]);
        $this->playMatch($context, $context->findGameBetween($games, $playerB, $playerC), $playerB, $playerC, [
            [11, 8],
            [11, 9],
            [9, 11],
            [8, 11],
            [11, 9],
        ]);
        $this->playMatch($context, $context->findGameBetween($games, $playerC, $playerA), $playerC, $playerA, [
            [11, 7],
            [11, 8],
            [8, 11],
            [9, 11],
            [11, 9],
        ]);

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('data.0.player_id', $playerA->id)
            ->assertJsonPath('data.1.player_id', $playerC->id)
            ->assertJsonPath('data.2.player_id', $playerB->id)
            ->assertJsonPath('meta.requires_manual_tiebreak', false);
    }

    public function test_resolves_triple_tie_with_points_difference(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition(setsToWin: 3);
        [$playerA, $playerB, $playerC, $playerD] = $context->createPlayers(4);
        $players = [$playerA, $playerB, $playerC, $playerD];
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()->where('group_id', $group->id)->get();

        $this->playMatch($context, $context->findGameBetween($games, $playerA, $playerD), $playerA, $playerD, [
            [11, 6],
            [11, 7],
            [11, 8],
        ]);
        $this->playMatch($context, $context->findGameBetween($games, $playerB, $playerD), $playerB, $playerD, [
            [11, 7],
            [11, 8],
            [11, 9],
        ]);
        $this->playMatch($context, $context->findGameBetween($games, $playerC, $playerD), $playerC, $playerD, [
            [11, 8],
            [11, 9],
            [11, 7],
        ]);

        $this->playMatch($context, $context->findGameBetween($games, $playerA, $playerB), $playerA, $playerB, [
            [11, 7],
            [11, 7],
            [8, 11],
            [11, 7],
        ]);
        $this->playMatch($context, $context->findGameBetween($games, $playerB, $playerC), $playerB, $playerC, [
            [11, 8],
            [11, 8],
            [8, 11],
            [11, 8],
        ]);
        $this->playMatch($context, $context->findGameBetween($games, $playerC, $playerA), $playerC, $playerA, [
            [11, 8],
            [11, 8],
            [8, 11],
            [11, 8],
        ]);

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('data.0.player_id', $playerA->id)
            ->assertJsonPath('data.1.player_id', $playerC->id)
            ->assertJsonPath('data.2.player_id', $playerB->id)
            ->assertJsonPath('data.3.player_id', $playerD->id)
            ->assertJsonPath('meta.requires_manual_tiebreak', false);
    }

    public function test_marks_tie_as_manual_when_all_criteria_remain_equal(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition(setsToWin: 3);
        [$playerA, $playerB, $playerC] = $context->createPlayers(3);
        $players = [$playerA, $playerB, $playerC];
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()->where('group_id', $group->id)->get();
        $balancedSets = [
            [11, 9],
            [11, 9],
            [9, 11],
            [11, 9],
        ];

        $this->playMatch($context, $context->findGameBetween($games, $playerA, $playerB), $playerA, $playerB, $balancedSets);
        $this->playMatch($context, $context->findGameBetween($games, $playerB, $playerC), $playerB, $playerC, $balancedSets);
        $this->playMatch($context, $context->findGameBetween($games, $playerC, $playerA), $playerC, $playerA, $balancedSets);

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('meta.requires_manual_tiebreak', true)
            ->assertJsonCount(1, 'meta.manual_tiebreak_groups')
            ->assertJsonPath('data.0.requires_manual_tiebreak', true)
            ->assertJsonPath('data.1.requires_manual_tiebreak', true)
            ->assertJsonPath('data.2.requires_manual_tiebreak', true);

        $manualIds = $response->json('meta.manual_tiebreak_groups.0.player_ids');
        sort($manualIds);

        $expectedIds = [$playerA->id, $playerB->id, $playerC->id];
        sort($expectedIds);

        $this->assertSame($expectedIds, $manualIds);
    }

    public function test_keeps_expected_order_when_no_tie_exists(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$playerA, $playerB, $playerC] = $context->createPlayers(3);
        $players = [$playerA, $playerB, $playerC];
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()->where('group_id', $group->id)->get();

        $this->playMatch($context, $context->findGameBetween($games, $playerA, $playerB), $playerA, $playerB, [[11, 8]]);
        $this->playMatch($context, $context->findGameBetween($games, $playerA, $playerC), $playerA, $playerC, [[11, 7]]);
        $this->playMatch($context, $context->findGameBetween($games, $playerB, $playerC), $playerB, $playerC, [[11, 9]]);

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('data.0.player_id', $playerA->id)
            ->assertJsonPath('data.1.player_id', $playerB->id)
            ->assertJsonPath('data.2.player_id', $playerC->id)
            ->assertJsonPath('data.0.requires_manual_tiebreak', false)
            ->assertJsonPath('data.1.requires_manual_tiebreak', false)
            ->assertJsonPath('data.2.requires_manual_tiebreak', false)
            ->assertJsonPath('meta.requires_manual_tiebreak', false)
            ->assertJsonPath('meta.manual_tiebreak_groups', []);
    }

    /**
     * @param  array<int, array{int, int}>  $sets
     */
    private function playMatch(
        TournamentTestContext $context,
        Game $game,
        Player $leftPlayer,
        Player $rightPlayer,
        array $sets,
    ): void {
        foreach ($sets as $index => [$leftScore, $rightScore]) {
            $player1IsLeft = (int) $game->player1_id === $leftPlayer->id;
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
