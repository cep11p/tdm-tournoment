<?php

namespace Tests\Feature\Auth;

use App\Enums\GameStatus;
use Tests\TestCase;

class GameAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
    }

    protected function tearDown(): void
    {
        $this->resetKeycloakClock();

        parent::tearDown();
    }

    public function test_games_index_remains_public(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $this->getJson($context->apiUrl("competitions/{$competition->id}/games"))
            ->assertOk();
    }

    public function test_create_game_requires_authentication(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $this->postJson($context->apiUrl("competitions/{$setup['competition']->id}/games"), [
            'player1_id' => $setup['playerOne']->id,
            'player2_id' => $setup['playerTwo']->id,
        ])
            ->assertUnauthorized();
    }

    public function test_create_game_forbidden_for_scorekeeper(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);

        $this->postJson($context->apiUrl("competitions/{$competition->id}/games"), [
            'player1_id' => $players[0]->id,
            'player2_id' => $players[1]->id,
        ], $this->keycloakAuthHeaders(['scorekeeper']))
            ->assertForbidden();
    }

    public function test_organizer_can_create_game(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);

        $context->createManualGame($competition, $players[0], $players[1]);
    }

    public function test_delete_game_forbidden_for_scorekeeper(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $context->deleteGame($setup['game'], ['scorekeeper'])
            ->assertForbidden();
    }

    public function test_organizer_can_delete_pending_game(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $context->deleteGame($setup['game'])
            ->assertNoContent();
    }

    public function test_scorekeeper_can_record_set(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $context->recordSet(
            $setup['game'],
            setNumber: 1,
            player1Score: 11,
            player2Score: 7,
            roles: ['scorekeeper'],
        )->assertOk();
    }

    public function test_player_cannot_record_set(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $context->recordSet(
            $setup['game'],
            setNumber: 1,
            player1Score: 11,
            player2Score: 7,
            roles: ['player'],
        )->assertForbidden();
    }

    public function test_organizer_with_permission_receives_domain_422_on_finished_game(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 1);

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 3)
            ->assertOk()
            ->assertJsonPath('data.status', GameStatus::Finished->value);

        $context->recordSet($setup['game'], setNumber: 2, player1Score: 11, player2Score: 3)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['game']);
    }
}
