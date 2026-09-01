<?php

namespace Tests\Feature\Game;

use App\Models\Game;
use Tests\TestCase;

class ManualGameTeamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_rejects_manual_game_creation_for_team_competition(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);

        $response = $this->postJson($context->apiUrl("competitions/{$competition->id}/games"), [
            'entry1_id' => $entries[0]->id,
            'entry2_id' => $entries[1]->id,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath(
                'errors.competition.0',
                'Los partidos de una competencia por equipos se gestionan dentro de sus enfrentamientos.',
            );

        $this->assertSame(0, Game::query()->where('competition_id', $competition->id)->count());
    }

    public function test_allows_manual_game_creation_for_singles_competition(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);

        $this->postJson($context->apiUrl("competitions/{$competition->id}/games"), [
            'player1_id' => $players[0]->id,
            'player2_id' => $players[1]->id,
        ])->assertCreated();

        $this->assertSame(1, Game::query()->where('competition_id', $competition->id)->count());
    }

    public function test_allows_manual_game_creation_for_doubles_competition(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(4);
        $entries = $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
        ]);

        $this->postJson($context->apiUrl("competitions/{$competition->id}/games"), [
            'entry1_id' => $entries[0]->id,
            'entry2_id' => $entries[1]->id,
        ])->assertCreated();

        $this->assertSame(1, Game::query()->where('competition_id', $competition->id)->count());
    }
}
