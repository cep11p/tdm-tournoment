<?php

namespace Tests\Feature\Auth;

use App\Enums\GameStatus;
use Tests\TestCase;

class GameResultCorrectionAuthorizationTest extends TestCase
{
    private const REASON = 'El árbitro informó que el marcador del segundo set fue cargado incorrectamente.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
    }

    protected function tearDown(): void
    {
        $this->resetKeycloakClock();

        parent::tearDown();
    }

    public function test_admin_can_correct_finished_game(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGame($context);

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
            ['admin'],
        )->assertOk()
            ->assertJsonPath('data.status', GameStatus::Finished->value);
    }

    public function test_organizer_cannot_correct_finished_game(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGame($context);

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
            ['organizer'],
        )->assertForbidden();
    }

    public function test_scorekeeper_cannot_correct_finished_game(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGame($context);

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
            ['scorekeeper'],
        )->assertForbidden();
    }

    public function test_player_cannot_correct_finished_game(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGame($context);

        $context->correctResult(
            $setup['game']->fresh(),
            self::REASON,
            [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
            ['player'],
        )->assertForbidden();
    }

    public function test_correction_requires_authentication(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedGame($context);

        $this->postJson($context->apiUrl("games/{$setup['game']->id}/corrections"), [
            'reason' => self::REASON,
            'sets' => [
                ['player1_score' => 11, 'player2_score' => 9],
                ['player1_score' => 11, 'player2_score' => 7],
            ],
        ])->assertUnauthorized();
    }

    /**
     * @return array{
     *     competition: \App\Models\Competition,
     *     playerOne: \App\Models\Player,
     *     playerTwo: \App\Models\Player,
     *     game: \App\Models\Game,
     * }
     */
    private function createFinishedGame(\Tests\Support\TournamentTestContext $context): array
    {
        $setup = $context->createPendingSinglesGame(setsToWin: 2, pointsPerSet: 11);

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 5)->assertOk();
        $context->recordSet($setup['game'], setNumber: 2, player1Score: 11, player2Score: 6)->assertOk();

        return $setup;
    }
}
