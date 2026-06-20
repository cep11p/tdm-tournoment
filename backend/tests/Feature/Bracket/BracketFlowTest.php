<?php

namespace Tests\Feature\Bracket;

use App\Models\Bracket;
use App\Models\Game;
use Tests\TestCase;

class BracketFlowTest extends TestCase
{
    public function test_creates_bracket_from_finished_groups_with_correct_seeding(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $response = $context->createBracket($setup['competition'], qualifiersPerGroup: 2);

        $response
            ->assertCreated()
            ->assertJsonPath('data.qualifiers_per_group', 2);

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $this->assertCount(2, $semifinals);
        $this->assertSame('Semifinal', $semifinals[0]->round);
        $this->assertSame('Semifinal', $semifinals[1]->round);

        $this->assertSame($setup['playerOne']->id, $semifinals[0]->player1_id);
        $this->assertSame($setup['playerFour']->id, $semifinals[0]->player2_id);
        $this->assertSame($setup['playerThree']->id, $semifinals[1]->player1_id);
        $this->assertSame($setup['playerTwo']->id, $semifinals[1]->player2_id);
    }

    public function test_rejects_bracket_when_group_games_are_pending(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: false);

        $groupAGame = Game::query()->where('group_id', $setup['groupA']->id)->sole();
        $context->finishGame($groupAGame, $setup['playerOne'])->assertOk();

        $response = $context->createBracket($setup['competition'], qualifiersPerGroup: 2);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group']);

        $this->assertDatabaseCount('brackets', 0);
    }

    public function test_rejects_second_bracket_in_same_competition(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $context->createBracket($setup['competition'], qualifiersPerGroup: 2)
            ->assertCreated();

        $response = $context->createBracket($setup['competition'], qualifiersPerGroup: 2);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);

        $this->assertDatabaseCount('brackets', 1);
    }

    public function test_generates_next_round_from_current_round_winners(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $context->createBracket($setup['competition'], qualifiersPerGroup: 2)
            ->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();
        $context->finishGame($semifinals[1], $setup['playerThree'])->assertOk();

        $response = $context->generateBracketNextRound($bracket);

        $response->assertCreated();

        $final = $context->bracketGamesForRound($bracket, 2);

        $this->assertCount(1, $final);
        $this->assertSame('Final', $final[0]->round);
        $this->assertSame(1, $final[0]->bracket_match);
        $this->assertSame($setup['playerOne']->id, $final[0]->player1_id);
        $this->assertSame($setup['playerThree']->id, $final[0]->player2_id);
    }

    public function test_rejects_next_round_when_current_round_is_incomplete(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $context->createBracket($setup['competition'], qualifiersPerGroup: 2)
            ->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();

        $response = $context->generateBracketNextRound($bracket);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bracket']);

        $this->assertSame(2, Game::query()->where('bracket_id', $bracket->id)->count());
    }

    public function test_rejects_duplicate_next_round_generation(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $context->createBracket($setup['competition'], qualifiersPerGroup: 2)
            ->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();
        $context->finishGame($semifinals[1], $setup['playerThree'])->assertOk();

        $context->generateBracketNextRound($bracket)->assertCreated();

        $response = $context->generateBracketNextRound($bracket);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bracket']);

        $this->assertSame(3, Game::query()->where('bracket_id', $bracket->id)->count());
    }

    public function test_rejects_next_round_when_final_is_already_finished(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $context->createBracket($setup['competition'], qualifiersPerGroup: 2)
            ->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();
        $context->finishGame($semifinals[1], $setup['playerThree'])->assertOk();

        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket, 2)->sole();
        $context->finishGame($final, $setup['playerOne'])->assertOk();

        $response = $context->generateBracketNextRound($bracket);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bracket']);

        $this->assertSame(3, Game::query()->where('bracket_id', $bracket->id)->count());
    }
}
