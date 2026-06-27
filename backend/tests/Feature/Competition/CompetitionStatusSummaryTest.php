<?php

namespace Tests\Feature\Competition;

use App\Models\Game;
use Tests\TestCase;

class CompetitionStatusSummaryTest extends TestCase
{
    public function test_competition_without_groups_returns_no_groups_status(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'no_groups')
            ->assertJsonPath('data.status_summary.label', 'Sin grupos');
    }

    public function test_competition_with_groups_but_no_group_games_returns_group_stage_pending(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createGroupWithPlayers($competition, $players);

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'group_stage_pending');
    }

    public function test_competition_with_pending_group_games_returns_group_stage_in_progress(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: false);

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'group_stage_in_progress');
    }

    public function test_competition_with_finished_groups_and_no_bracket_returns_ready_for_bracket(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'ready_for_bracket')
            ->assertJsonPath('data.status_summary.next_action', 'Generar llave eliminatoria');
    }

    public function test_competition_with_bracket_and_pending_games_returns_knockout_in_progress(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'knockout_in_progress');
    }

    public function test_competition_with_finished_final_returns_completed(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = $setup['competition']->fresh()->brackets()->firstOrFail();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        foreach ($semifinals as $game) {
            if (! $game->is_bye) {
                $context->finishGame($game, $game->player1)->assertOk();
            }
        }

        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->sole();
        $context->finishGame($final, $final->player1)->assertOk();

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'completed')
            ->assertJsonPath('data.status_summary.next_action', 'Ver llave');
    }

    public function test_knockout_in_progress_suggests_generate_next_round_when_current_round_is_complete(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = $setup['competition']->fresh()->brackets()->firstOrFail();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        foreach ($semifinals as $game) {
            if (! $game->is_bye) {
                $context->finishGame($game, $game->player1)->assertOk();
            }
        }

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'knockout_in_progress')
            ->assertJsonPath('data.status_summary.next_action', 'Generar siguiente ronda');

        $this->assertFalse(
            Game::query()
                ->where('competition_id', $setup['competition']->id)
                ->where('round', 'Final')
                ->exists()
        );
    }

    public function test_knockout_direct_with_insufficient_registrations_returns_awaiting_registrations(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(1);
        $context->registerPlayers($competition, $players);

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'awaiting_registrations')
            ->assertJsonPath('data.status_summary.label', 'Esperando inscriptos')
            ->assertJsonPath('data.has_group_stage', false);
    }

    public function test_knockout_direct_with_enough_registrations_returns_ready_for_bracket(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'ready_for_bracket')
            ->assertJsonPath('data.status_summary.label', 'Lista para generar llave');
    }

    public function test_knockout_direct_with_bracket_returns_knockout_in_progress(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'knockout_in_progress');

        $this->assertNotSame('no_groups', $response->json('data.status_summary.code'));
    }

    public function test_knockout_direct_never_returns_no_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response->assertOk();

        $this->assertNotSame('no_groups', $response->json('data.status_summary.code'));
    }
}
