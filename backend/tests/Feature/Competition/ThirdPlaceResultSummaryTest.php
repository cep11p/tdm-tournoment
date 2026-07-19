<?php

namespace Tests\Feature\Competition;

use App\Enums\ThirdPlaceMode;
use App\Models\Bracket;
use App\Models\Game;
use App\Models\Player;
use Tests\TestCase;

class ThirdPlaceResultSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_none_mode_returns_empty_third_place_after_final(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $setup['competition']->update(['third_place_mode' => ThirdPlaceMode::None]);
        $setup['competition']->refresh();

        $context->completeCompetitionThroughFinal($setup['competition']);

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.result_summary.third_place_mode', ThirdPlaceMode::None->value)
            ->assertJsonPath('data.result_summary.third_place', [])
            ->assertJsonPath('data.result_summary.fourth_place', null)
            ->assertJsonPath('data.result_summary.third_place_game_id', null)
            ->assertJsonPath('data.result_summary.champion.id', fn ($value): bool => $value !== null);
    }

    public function test_playoff_mode_returns_empty_third_place_when_final_pending(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Playoff]);
        $competition->refresh();

        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);
        $context->finishGame($semifinals[0], $players[0])->assertOk();
        $context->finishGame($semifinals[1], $players[2])->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary', null);
    }

    public function test_playoff_mode_returns_pending_third_place_after_final(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $setup['competition']->update(['third_place_mode' => ThirdPlaceMode::Playoff]);
        $setup['competition']->refresh();

        $context->createBracket($setup['competition'])->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);
        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();
        $context->finishGame($semifinals[1], $setup['playerThree'])->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->sole();
        $context->finishGame($final, $setup['playerOne'])->assertOk();

        $thirdPlace = Game::query()
            ->where('bracket_id', $bracket->id)
            ->where('bracket_purpose', \App\Enums\BracketGamePurpose::ThirdPlace)
            ->sole();

        $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary.third_place_mode', ThirdPlaceMode::Playoff->value)
            ->assertJsonPath('data.result_summary.third_place', [])
            ->assertJsonPath('data.result_summary.fourth_place', null)
            ->assertJsonPath('data.result_summary.third_place_game_id', $thirdPlace->id);
    }

    public function test_shared_mode_with_four_players_returns_two_third_places(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Shared]);
        $competition->refresh();

        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($semifinals[0], $players[0])->assertOk();
        $context->finishGame($semifinals[1], $players[2])->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->sole();
        $context->finishGame($final, $players[0])->assertOk();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.result_summary.third_place_mode', ThirdPlaceMode::Shared->value)
            ->assertJsonCount(2, 'data.result_summary.third_place')
            ->assertJsonPath('data.result_summary.third_place.0.id', $players[3]->id)
            ->assertJsonPath('data.result_summary.third_place.1.id', $players[1]->id)
            ->assertJsonPath('data.result_summary.fourth_place', null);
    }

    public function test_shared_mode_with_three_players_returns_empty_third_place(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Shared]);
        $competition->refresh();

        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $context->completeCompetitionThroughFinal($competition);

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary.third_place_mode', ThirdPlaceMode::Shared->value)
            ->assertJsonPath('data.result_summary.third_place', []);
    }

    public function test_shared_mode_with_two_players_returns_empty_third_place(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Shared]);
        $competition->refresh();

        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->completeCompetitionThroughFinal($competition);

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary.third_place', []);
    }

    public function test_shared_mode_with_five_players_returns_two_third_places(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Shared]);
        $competition->refresh();

        $players = $context->createPlayers(5);
        $context->registerPlayers($competition, $players);
        $context->completeCompetitionThroughFinal($competition);

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data.result_summary.third_place');
    }

    public function test_shared_mode_with_six_players_returns_two_third_places(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Shared]);
        $competition->refresh();

        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);
        $context->completeCompetitionThroughFinal($competition);

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonCount(2, 'data.result_summary.third_place');
    }

    public function test_final_pending_returns_null_result_summary(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $setup['competition']->update(['third_place_mode' => ThirdPlaceMode::Shared]);
        $setup['competition']->refresh();
        $context->createBracket($setup['competition'])->assertCreated();

        $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary', null);
    }

    public function test_groups_knockout_shared_podio_uses_bracket_only(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $setup['competition']->update(['third_place_mode' => ThirdPlaceMode::Shared]);
        $setup['competition']->refresh();

        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();
        $context->finishGame($semifinals[1], $setup['playerThree'])->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->sole();
        $context->finishGame($final, $setup['playerOne'])->assertOk();

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.result_summary.champion.id', $setup['playerOne']->id)
            ->assertJsonPath('data.result_summary.runner_up.id', $setup['playerThree']->id)
            ->assertJsonCount(2, 'data.result_summary.third_place')
            ->assertJsonPath('data.result_summary.third_place.0.id', $setup['playerFour']->id)
            ->assertJsonPath('data.result_summary.third_place.1.id', $setup['playerTwo']->id);
    }

    public function test_tournament_close_includes_shared_third_places_in_results_summary(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $setup['competition']->update(['third_place_mode' => ThirdPlaceMode::Shared]);
        $setup['competition']->refresh();

        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();
        $context->finishGame($semifinals[1], $setup['playerThree'])->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->sole();
        $context->finishGame($final, $setup['playerOne'])->assertOk();

        $response = $context->closeTournament($setup['competition']->tournament);

        $response
            ->assertOk()
            ->assertJsonPath('data.results_summary.results.0.third_place_mode', ThirdPlaceMode::Shared->value)
            ->assertJsonCount(2, 'data.results_summary.results.0.third_place')
            ->assertJsonPath('data.results_summary.results.0.third_place.0.id', $setup['playerFour']->id)
            ->assertJsonPath('data.results_summary.results.0.third_place.1.id', $setup['playerTwo']->id);
    }
}
