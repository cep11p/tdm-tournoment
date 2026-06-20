<?php

namespace Tests\Feature\Registration;

use Tests\TestCase;

class RegistrationConstraintsTest extends TestCase
{
    public function test_rejects_duplicate_registration_in_same_competition(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competition, $player)
            ->assertCreated();

        $response = $context->registerPlayerViaApi($competition, $player);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);

        $this->assertDatabaseCount('registrations', 1);
    }

    public function test_rejects_manual_game_with_unregistered_player(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$registeredPlayer, $unregisteredPlayer] = $context->createPlayers(2);

        $context->registerPlayerViaApi($competition, $registeredPlayer)
            ->assertCreated();

        $response = $this->postJson($context->apiUrl("competitions/{$competition->id}/games"), [
            'player1_id' => $registeredPlayer->id,
            'player2_id' => $unregisteredPlayer->id,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player2_id']);

        $this->assertDatabaseCount('games', 0);
    }
}
