<?php

namespace Tests\Feature\Competition;

use App\Enums\GameStatus;
use App\Models\Game;
use Tests\TestCase;

class CompetitionResultSummaryTest extends TestCase
{
    public function test_competition_without_bracket_returns_null_result_summary(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.result_summary', null);
    }

    public function test_competition_with_bracket_in_progress_returns_null_result_summary(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.result_summary', null);
    }

    public function test_competition_with_finished_final_returns_champion_and_runner_up(): void
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
        $champion = $final->player1;
        $runnerUp = $final->player2;

        $context->finishGame($final, $champion)->assertOk();

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.result_summary.champion.id', $champion->id)
            ->assertJsonPath('data.result_summary.champion.name', trim("{$champion->first_name} {$champion->last_name}"))
            ->assertJsonPath('data.result_summary.runner_up.id', $runnerUp->id)
            ->assertJsonPath('data.result_summary.runner_up.name', trim("{$runnerUp->first_name} {$runnerUp->last_name}"))
            ->assertJsonPath('data.result_summary.final_game_id', $final->id);
    }

    public function test_show_competition_includes_result_summary_champion_name(): void
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
        $champion = $final->player1;
        $context->finishGame($final, $champion)->assertOk();

        $expectedName = trim("{$champion->first_name} {$champion->last_name}");

        $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary.champion.name', $expectedName);
    }

    public function test_finished_final_without_winner_returns_null_result_summary(): void
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

        Game::query()->whereKey($final->id)->update([
            'status' => GameStatus::Finished,
            'winner_id' => null,
        ]);

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.result_summary', null);
    }
}
