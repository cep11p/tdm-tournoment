<?php

namespace Tests\Feature\Tournament;

use App\Enums\GameStatus;
use App\Enums\TournamentStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Tournament;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CloseTournamentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_closes_tournament_with_one_completed_competition(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $result = $context->completeCompetitionThroughFinal($setup['competition']);

        $tournament = $setup['competition']->tournament;

        $response = $context->closeTournament($tournament);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', TournamentStatus::Finished->value)
            ->assertJsonPath('data.closed_at', fn (?string $value): bool => $value !== null)
            ->assertJsonPath('data.results_summary.completed_competitions', 1)
            ->assertJsonPath('data.results_summary.results.0.champion_id', $result['champion']->id)
            ->assertJsonPath('data.results_summary.results.0.runner_up_id', $result['runner_up']->id);

        $this->assertDatabaseHas('tournaments', [
            'id' => $tournament->id,
            'status' => TournamentStatus::Finished->value,
        ]);

        $this->assertNotNull($tournament->fresh()->closed_at);

        $activity = Activity::query()->where('description', 'tournament.closed')->sole();

        $this->assertSame('finished', data_get($activity->properties, 'new.status'));
        $this->assertNotNull(data_get($activity->properties, 'new.closed_at'));
        $this->assertSame(1, data_get($activity->properties, 'summary.completed_competitions'));
        $this->assertSame($result['champion']->id, data_get($activity->properties, 'summary.results.0.champion_id'));
    }

    public function test_closes_tournament_with_multiple_completed_competitions(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();

        $first = $context->createFourQualifierGroupPhase();
        $first['competition']->update(['tournament_id' => $tournament->id]);
        $context->completeCompetitionThroughFinal($first['competition']);

        $secondSetup = $context->createFourQualifierGroupPhase();
        $secondSetup['competition']->update(['tournament_id' => $tournament->id]);
        $context->completeCompetitionThroughFinal($secondSetup['competition']);

        $response = $context->closeTournament($tournament);

        $response
            ->assertOk()
            ->assertJsonPath('data.results_summary.competitions_count', 2)
            ->assertJsonPath('data.results_summary.completed_competitions', 2);
    }

    public function test_unused_competition_does_not_block_closure(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);

        $tournament = $setup['competition']->tournament;

        Competition::query()->create([
            'tournament_id' => $tournament->id,
            'name' => 'Sin uso',
            'type' => $setup['competition']->type,
            'category' => 'segunda',
            'format' => $setup['competition']->format,
            'sets_to_win' => 3,
            'points_per_set' => 11,
        ]);

        $response = $context->closeTournament($tournament);

        $response
            ->assertOk()
            ->assertJsonPath('data.results_summary.competitions_count', 2)
            ->assertJsonPath('data.results_summary.completed_competitions', 1)
            ->assertJsonPath('data.results_summary.unused_competitions', 1);
    }

    public function test_rejects_tournament_without_competitions(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();

        Activity::query()->delete();

        $context->closeTournament($tournament)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);

        $this->assertSame(TournamentStatus::InProgress, $tournament->fresh()->status);
        $this->assertNull($tournament->fresh()->closed_at);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_rejects_incomplete_competition(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: false);
        $tournament = $setup['competition']->tournament;

        Activity::query()->delete();

        $context->closeTournament($tournament)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);

        $this->assertNull($tournament->fresh()->closed_at);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_rejects_competition_with_pending_games(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);

        Game::query()->create([
            'competition_id' => $setup['competition']->id,
            'player1_id' => $setup['playerOne']->id,
            'player2_id' => $setup['playerTwo']->id,
            'status' => GameStatus::Pending,
            'round' => 'Manual',
        ]);

        Activity::query()->delete();

        $context->closeTournament($setup['competition']->tournament)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_rejects_competition_without_resolvable_champion(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        Activity::query()->delete();

        $context->closeTournament($setup['competition']->tournament)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_rejects_double_close(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);

        $tournament = $setup['competition']->tournament;
        $context->closeTournament($tournament)->assertOk();

        Activity::query()->delete();

        $context->closeTournament($tournament)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_patch_status_finished_is_rejected(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();

        $this->patchJson($context->apiUrl("tournaments/{$tournament->id}"), [
            'status' => TournamentStatus::Finished->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_closed_tournament_allows_descriptive_update(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);

        $tournament = $setup['competition']->tournament;
        $context->closeTournament($tournament)->assertOk();

        $closedAt = $tournament->fresh()->closed_at;

        $this->patchJson($context->apiUrl("tournaments/{$tournament->id}"), [
            'name' => 'Torneo Renombrado',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Torneo Renombrado')
            ->assertJsonPath('data.status', TournamentStatus::Finished->value);

        $this->assertTrue($tournament->fresh()->closed_at->equalTo($closedAt));
    }

    public function test_closed_tournament_rejects_status_change_via_patch(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);

        $tournament = $setup['competition']->tournament;
        $context->closeTournament($tournament)->assertOk();

        $this->patchJson($context->apiUrl("tournaments/{$tournament->id}"), [
            'status' => TournamentStatus::InProgress->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }
}
