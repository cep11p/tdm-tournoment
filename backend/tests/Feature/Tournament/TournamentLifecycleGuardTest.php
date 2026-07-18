<?php

namespace Tests\Feature\Tournament;

use App\Enums\GameStatus;
use App\Enums\TournamentStatus;
use App\Models\Game;
use App\Support\Tournament\TournamentLifecycleGuard;
use Tests\TestCase;

class TournamentLifecycleGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_guard_blocks_when_tournament_is_finished(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament([
            'status' => TournamentStatus::Finished,
            'closed_at' => now(),
        ]);

        try {
            TournamentLifecycleGuard::ensureMutable($tournament);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertSame(
                TournamentLifecycleGuard::LOCK_MESSAGE,
                $exception->errors()['tournament'][0],
            );
        }
    }

    public function test_create_competition_is_blocked_after_close(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);

        $tournament = $setup['competition']->tournament;
        $context->closeTournament($tournament)->assertOk();

        $context->createCompetitionViaApi($tournament->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);
    }

    public function test_registration_is_blocked_after_close(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);

        $tournament = $setup['competition']->tournament;
        $context->closeTournament($tournament)->assertOk();

        $player = $context->createPlayers(1)[0];

        $this->postJson($context->apiUrl("competitions/{$setup['competition']->id}/registrations"), [
            'player_id' => $player->id,
        ], $this->authHeaders(['organizer']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);
    }

    public function test_generate_random_groups_is_blocked_after_close(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);

        $tournament = $setup['competition']->tournament;
        $context->closeTournament($tournament)->assertOk();

        $context->generateRandomGroups($setup['competition'], 2)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);
    }

    public function test_create_bracket_is_blocked_after_close(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);

        $tournament = $setup['competition']->tournament;
        $context->closeTournament($tournament)->assertUnprocessable();

        $tournament->update([
            'status' => TournamentStatus::Finished,
            'closed_at' => now(),
        ]);

        $context->createBracket($setup['competition'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);
    }

    public function test_record_set_is_blocked_after_close(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);
        $context->closeTournament($setup['competition']->tournament)->assertOk();

        $pendingGame = Game::query()->create([
            'competition_id' => $setup['competition']->id,
            'player1_id' => $setup['playerOne']->id,
            'player2_id' => $setup['playerTwo']->id,
            'status' => GameStatus::Pending,
            'round' => 'Manual',
            'best_of' => 1,
            'sets_to_win' => 1,
        ]);

        $this->postJson($context->apiUrl("games/{$pendingGame->id}/sets"), [
            'set_number' => 1,
            'player1_score' => 11,
            'player2_score' => 0,
        ], $this->authHeaders(['organizer']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);
    }

    public function test_update_competition_is_blocked_after_close(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);
        $context->closeTournament($setup['competition']->tournament)->assertOk();

        $context->updateCompetitionViaApi($setup['competition'], [
            'name' => 'Nuevo nombre',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);
    }
}
