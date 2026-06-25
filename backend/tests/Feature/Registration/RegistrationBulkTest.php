<?php

namespace Tests\Feature\Registration;

use Tests\TestCase;

class RegistrationBulkTest extends TestCase
{
    public function test_registers_multiple_new_players(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $playerIds = array_map(static fn ($player) => $player->id, $players);

        $response = $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => $playerIds],
        );

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Inscripción masiva procesada.',
                'created' => 3,
                'skipped' => 0,
                'total' => 3,
            ]);

        $this->assertDatabaseCount('registrations', 3);
    }

    public function test_skips_players_already_registered_and_registers_new_ones(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$registeredPlayer, $newPlayerOne, $newPlayerTwo] = $context->createPlayers(3);

        $context->registerPlayer($competition, $registeredPlayer);

        $response = $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            [
                'player_ids' => [
                    $registeredPlayer->id,
                    $newPlayerOne->id,
                    $newPlayerTwo->id,
                ],
            ],
        );

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Inscripción masiva procesada.',
                'created' => 2,
                'skipped' => 1,
                'total' => 3,
            ]);

        $this->assertDatabaseCount('registrations', 3);
    }

    public function test_identical_second_call_is_idempotent(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $playerIds = array_map(static fn ($player) => $player->id, $players);

        $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => $playerIds],
        )->assertOk();

        $response = $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => $playerIds],
        );

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Inscripción masiva procesada.',
                'created' => 0,
                'skipped' => 2,
                'total' => 2,
            ]);

        $this->assertDatabaseCount('registrations', 2);
    }

    public function test_rejects_missing_player_ids(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $response = $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            [],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids']);
    }

    public function test_rejects_empty_player_ids(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $response = $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => []],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids']);
    }

    public function test_rejects_nonexistent_player_id(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $response = $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => [$player->id, 999999]],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids.1']);
    }

    public function test_rejects_duplicate_player_ids_in_payload(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $response = $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => [$player->id, $player->id]],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids.1']);
    }
}
