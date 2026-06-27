<?php

namespace Tests\Feature\Bracket;

use App\Enums\CompetitionFormat;
use App\Models\Bracket;
use Tests\TestCase;

class BracketDirectFlowTest extends TestCase
{
    public function test_creates_bracket_from_registrations_without_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $response = $context->createBracket($competition);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 4)
            ->assertJsonPath('data.byes_count', 0)
            ->assertJsonPath('data.qualifiers_per_group', 0)
            ->assertJsonCount(2, 'data.games');

        $this->assertDatabaseCount('groups', 0);
        $this->assertDatabaseCount('brackets', 1);
    }

    public function test_seeds_players_in_registration_order(): void
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

    public function test_rejects_bracket_with_fewer_than_two_registrations(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(1);
        $context->registerPlayers($competition, $players);

        $response = $context->createBracket($competition);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);

        $this->assertDatabaseCount('brackets', 0);
    }

    public function test_rejects_second_bracket_in_same_competition(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $context->createBracket($competition)->assertCreated();

        $response = $context->createBracket($competition);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);

        $this->assertDatabaseCount('brackets', 1);
    }

    public function test_rejects_bracket_when_competition_has_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createGroupWithPlayers($competition, array_slice($players, 0, 2), 'Grupo A');

        $response = $context->createBracket($competition);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);

        $this->assertDatabaseCount('brackets', 0);
    }

    public function test_creates_bracket_with_bye_for_three_registrations(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);

        $response = $context->createBracket($competition);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 4)
            ->assertJsonPath('data.byes_count', 1)
            ->assertJsonCount(2, 'data.games');

        $byeGames = collect($response->json('data.games'))
            ->filter(fn (array $game): bool => ($game['is_bye'] ?? false) === true);

        $this->assertCount(1, $byeGames);
        $this->assertSame($players[0]->id, $byeGames->first()['player1']['id']);
    }

    public function test_creates_bracket_with_byes_for_six_registrations(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $response = $context->createBracket($competition);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 8)
            ->assertJsonPath('data.byes_count', 2)
            ->assertJsonCount(4, 'data.games');
    }

    public function test_groups_knockout_still_requires_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $competition->update(['format' => CompetitionFormat::GroupsKnockout]);
        $competition->refresh();

        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $response = $context->createBracket($competition);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);
    }
}
