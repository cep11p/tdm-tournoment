<?php

namespace Tests\Feature\Registration;

use App\Support\Competition\CompetitionStructureGuard;
use App\Support\Competition\RegistrationGuard;
use App\Models\Game;
use Tests\TestCase;

class RegistrationConstraintsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

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

    public function test_rejects_registration_when_bracket_exists(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $response = $context->registerPlayerViaApi(
            $competition,
            $context->createPlayers(1)[0],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', RegistrationGuard::LOCK_MESSAGE);

        $this->assertDatabaseCount('registrations', 3);
    }

    public function test_allows_registration_before_bracket_is_generated(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $context->registerPlayers($competition, $context->createPlayers(2));

        $response = $context->registerPlayerViaApi(
            $competition,
            $context->createPlayers(1)[0],
        );

        $response->assertCreated();
        $this->assertDatabaseCount('registrations', 3);
    }

    public function test_bracket_lock_message_takes_priority_over_structure_lock(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $realGame = Game::query()
            ->where('competition_id', $competition->id)
            ->where(function ($query): void {
                $query->where('is_bye', false)->orWhereNull('is_bye');
            })
            ->firstOrFail();

        $context->recordSet($realGame, setNumber: 1, player1Score: 11, player2Score: 5)
            ->assertOk();

        $response = $context->registerPlayerViaApi(
            $competition,
            $context->createPlayers(1)[0],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', RegistrationGuard::LOCK_MESSAGE);
    }

    public function test_rejects_registration_when_games_are_in_progress(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 5)
            ->assertOk();

        $response = $context->registerPlayerViaApi(
            $setup['competition'],
            $context->createPlayers(1)[0],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', CompetitionStructureGuard::LOCK_MESSAGE);
    }
}
