<?php

namespace Tests\Feature\Game;

use App\Enums\GameStatus;
use App\Models\Game;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecordGameSetTest extends TestCase
{
    public function test_first_set_moves_game_to_in_progress(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $response = $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 7);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', GameStatus::InProgress->value)
            ->assertJsonPath('data.winner_id', null)
            ->assertJsonPath('data.finished_at', null)
            ->assertJsonPath('data.sets_won.player1', 1)
            ->assertJsonPath('data.sets_won.player2', 0);

        $this->assertDatabaseHas('games', [
            'id' => $setup['game']->id,
            'status' => GameStatus::InProgress->value,
            'winner_id' => null,
        ]);
    }

    public function test_enough_sets_define_winner_and_finished_status(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 2, pointsPerSet: 11);

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 5)
            ->assertOk()
            ->assertJsonPath('data.status', GameStatus::InProgress->value);

        $context->recordSet($setup['game'], setNumber: 2, player1Score: 8, player2Score: 11)
            ->assertOk()
            ->assertJsonPath('data.status', GameStatus::InProgress->value)
            ->assertJsonPath('data.sets_won.player1', 1)
            ->assertJsonPath('data.sets_won.player2', 1);

        $response = $context->recordSet($setup['game'], setNumber: 3, player1Score: 11, player2Score: 9);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', GameStatus::Finished->value)
            ->assertJsonPath('data.winner_id', $setup['playerOne']->id)
            ->assertJsonPath('data.sets_won.player1', 2)
            ->assertJsonPath('data.sets_won.player2', 1);

        $this->assertNotNull($response->json('data.finished_at'));

        $game = Game::query()->findOrFail($setup['game']->id);
        $this->assertSame(GameStatus::Finished, $game->status);
        $this->assertSame($setup['playerOne']->id, $game->winner_id);
        $this->assertNotNull($game->finished_at);
    }

    public function test_rejects_recording_sets_on_finished_game(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 2, pointsPerSet: 11);

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 4)
            ->assertOk();
        $context->recordSet($setup['game'], setNumber: 2, player1Score: 11, player2Score: 6)
            ->assertOk()
            ->assertJsonPath('data.status', GameStatus::Finished->value);

        $response = $context->recordSet($setup['game'], setNumber: 3, player1Score: 11, player2Score: 2);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['game']);

        $this->assertDatabaseCount('game_sets', 2);
    }

    public function test_rejects_tied_set_scores(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $response = $context->recordSet($setup['game'], setNumber: 1, player1Score: 10, player2Score: 10);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player1_score']);

        $this->assertDatabaseCount('game_sets', 0);
    }

    public function test_rejects_scores_below_points_per_set(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(pointsPerSet: 11);

        $response = $context->recordSet($setup['game'], setNumber: 1, player1Score: 10, player2Score: 8);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player1_score']);

        $this->assertDatabaseCount('game_sets', 0);
    }

    public function test_rejects_duplicate_set_number(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 9)
            ->assertOk();

        $response = $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 5);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['set_number']);

        $this->assertDatabaseCount('game_sets', 1);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function invalidFinalSetScoreProvider(): array
    {
        return [
            '11-10' => [11, 10],
            '12-11' => [12, 11],
            '20-19' => [20, 19],
            '20-10' => [20, 10],
            '50-10' => [50, 10],
            '50-45' => [50, 45],
            '50-47' => [50, 47],
        ];
    }

    #[DataProvider('invalidFinalSetScoreProvider')]
    public function test_rejects_invalid_final_set_score(int $player1Score, int $player2Score): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(pointsPerSet: 11);

        $response = $context->recordSet($setup['game'], setNumber: 1, player1Score: $player1Score, player2Score: $player2Score);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player1_score']);

        $this->assertDatabaseCount('game_sets', 0);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function validFinalSetScoreProvider(): array
    {
        return [
            '11-0' => [11, 0],
            '11-5' => [11, 5],
            '11-9' => [11, 9],
            '12-10' => [12, 10],
            '13-11' => [13, 11],
            '14-12' => [14, 12],
            '20-18' => [20, 18],
            '52-50' => [52, 50],
        ];
    }

    #[DataProvider('validFinalSetScoreProvider')]
    public function test_accepts_valid_final_set_score(int $player1Score, int $player2Score): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(pointsPerSet: 11);

        $response = $context->recordSet($setup['game'], setNumber: 1, player1Score: $player1Score, player2Score: $player2Score);

        $response->assertOk();

        $this->assertDatabaseCount('game_sets', 1);
    }
}
