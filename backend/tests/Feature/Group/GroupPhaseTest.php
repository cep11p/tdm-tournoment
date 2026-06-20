<?php

namespace Tests\Feature\Group;

use App\Models\Game;
use App\Models\Player;
use Tests\TestCase;

class GroupPhaseTest extends TestCase
{
    public function test_round_robin_generates_all_pairings_for_group(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $response = $this->postJson($context->apiUrl("groups/{$group->id}/round-robin-games"));

        $response
            ->assertCreated()
            ->assertJsonCount(3, 'data');

        $this->assertDatabaseCount('games', 3);
        $this->assertSame(3, Game::query()->where('group_id', $group->id)->count());
    }

    public function test_round_robin_generation_is_idempotent(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $this->postJson($context->apiUrl("groups/{$group->id}/round-robin-games"))
            ->assertCreated();

        $response = $this->postJson($context->apiUrl("groups/{$group->id}/round-robin-games"));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group']);

        $this->assertSame(3, Game::query()->where('group_id', $group->id)->count());
    }

    public function test_group_standings_order_players_by_wins_losses_and_name(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        [$playerOne, $playerTwo, $playerThree] = $players;
        $group = $context->createGroupWithPlayers($competition, $players);

        $this->postJson($context->apiUrl("groups/{$group->id}/round-robin-games"))
            ->assertCreated();

        $games = Game::query()
            ->where('group_id', $group->id)
            ->get();

        $this->assertCount(3, $games);

        $findGameBetween = function (Player $left, Player $right) use ($games): Game {
            $game = $games->first(
                fn (Game $candidate): bool => (
                    (int) $candidate->player1_id === $left->id && (int) $candidate->player2_id === $right->id
                ) || (
                    (int) $candidate->player1_id === $right->id && (int) $candidate->player2_id === $left->id
                )
            );

            $this->assertNotNull($game);

            return $game;
        };

        $context->finishGame($findGameBetween($playerOne, $playerTwo), $playerOne)->assertOk();
        $context->finishGame($findGameBetween($playerOne, $playerThree), $playerOne)->assertOk();
        $context->finishGame($findGameBetween($playerTwo, $playerThree), $playerTwo)->assertOk();

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.player_id', $playerOne->id)
            ->assertJsonPath('data.0.won', 2)
            ->assertJsonPath('data.0.lost', 0)
            ->assertJsonPath('data.1.player_id', $playerTwo->id)
            ->assertJsonPath('data.1.won', 1)
            ->assertJsonPath('data.1.lost', 1)
            ->assertJsonPath('data.2.player_id', $playerThree->id)
            ->assertJsonPath('data.2.won', 0)
            ->assertJsonPath('data.2.lost', 2);
    }

    public function test_player_cannot_be_assigned_to_two_groups_in_same_competition(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        [$playerOne, $playerTwo] = $players;

        $groupA = $context->createGroup($competition, 'Grupo A');
        $groupB = $context->createGroup($competition, 'Grupo B');

        $this->postJson($context->apiUrl("groups/{$groupA->id}/players"), [
            'player_id' => $playerOne->id,
        ])->assertCreated();

        $this->postJson($context->apiUrl("groups/{$groupA->id}/players"), [
            'player_id' => $playerTwo->id,
        ])->assertCreated();

        $response = $this->postJson($context->apiUrl("groups/{$groupB->id}/players"), [
            'player_id' => $playerOne->id,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);
    }
}
