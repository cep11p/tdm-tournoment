<?php

namespace Tests\Feature\Game;

use App\Enums\GameStatus;
use App\Enums\TournamentStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Tournament;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GameFormatFlowTest extends TestCase
{
    public function test_created_competition_exposes_default_stage_best_of_values(): void
    {
        $context = $this->tournamentContext();
        $tournament = Tournament::query()->create([
            'name' => 'Torneo Defaults',
            'location' => 'Club Test',
            'start_date' => Carbon::today()->toDateString(),
            'status' => TournamentStatus::Draft,
        ]);

        $response = $context->createCompetitionViaApi($tournament->id);

        $response
            ->assertCreated()
            ->assertJsonMissingPath('data.sets_to_win')
            ->assertJsonPath('data.group_stage_best_of', 5)
            ->assertJsonPath('data.knockout_stage_best_of', 5)
            ->assertJsonPath('data.semifinal_best_of', 7)
            ->assertJsonPath('data.final_best_of', 7);
    }

    public function test_competition_can_be_created_without_sets_to_win_payload(): void
    {
        $context = $this->tournamentContext();
        $tournament = Tournament::query()->create([
            'name' => 'Torneo Sin Legacy',
            'location' => 'Club Test',
            'start_date' => Carbon::today()->toDateString(),
            'status' => TournamentStatus::Draft,
        ]);

        $response = $context->createCompetitionViaApi($tournament->id, [
            'group_stage_best_of' => 5,
            'knockout_stage_best_of' => 5,
            'semifinal_best_of' => 7,
            'final_best_of' => 7,
        ]);

        $response->assertCreated();

        $competition = Competition::query()->findOrFail((int) $response->json('data.id'));

        $this->assertSame(3, $competition->sets_to_win);
        $this->assertSame(5, $competition->group_stage_best_of);
    }

    public function test_legacy_game_without_snapshot_uses_competition_sets_to_win_fallback(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 1);

        $setup['game']->update([
            'best_of' => null,
            'sets_to_win' => null,
        ]);

        $response = $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 7);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', GameStatus::Finished->value)
            ->assertJsonPath('data.sets_won.player1', 1);
    }

    public function test_round_robin_games_store_group_stage_format_snapshot(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition(setsToWin: 2);
        $competition->update([
            'group_stage_best_of' => 5,
            'knockout_stage_best_of' => 5,
            'semifinal_best_of' => 7,
            'final_best_of' => 7,
        ]);

        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();

        $game = Game::query()->where('group_id', $group->id)->sole();

        $this->assertSame(5, $game->best_of);
        $this->assertSame(3, $game->sets_to_win);
    }

    public function test_bracket_games_store_semifinal_format_snapshot(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $setup['competition']->update([
            'group_stage_best_of' => 3,
            'knockout_stage_best_of' => 5,
            'semifinal_best_of' => 7,
            'final_best_of' => 7,
        ]);
        $setup['competition']->refresh();

        $context->createBracket($setup['competition'])->assertCreated();

        $realGame = Game::query()
            ->where('competition_id', $setup['competition']->id)
            ->where('is_bye', false)
            ->whereNotNull('bracket_id')
            ->first();

        $this->assertNotNull($realGame);
        $this->assertSame(7, $realGame->best_of);
        $this->assertSame(4, $realGame->sets_to_win);
        $this->assertSame('Semifinal', $realGame->round);
    }

    public function test_generate_next_round_uses_final_format(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $setup['competition']->update([
            'group_stage_best_of' => 3,
            'knockout_stage_best_of' => 5,
            'semifinal_best_of' => 7,
            'final_best_of' => 7,
        ]);
        $setup['competition']->refresh();

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

        $this->assertSame(7, $final->best_of);
        $this->assertSame(4, $final->sets_to_win);
        $this->assertSame('Final', $final->round);
    }

    public function test_record_set_uses_game_snapshot_for_best_of_three(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 2);

        $setup['game']->update([
            'best_of' => 3,
            'sets_to_win' => 2,
        ]);

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 5)->assertOk();

        $response = $context->recordSet($setup['game'], setNumber: 2, player1Score: 11, player2Score: 9);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', GameStatus::Finished->value)
            ->assertJsonPath('data.sets_won.player1', 2);
    }

    public function test_record_set_uses_game_snapshot_for_best_of_five(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 2);

        $setup['game']->update([
            'best_of' => 5,
            'sets_to_win' => 3,
        ]);

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 5)->assertOk();
        $context->recordSet($setup['game'], setNumber: 2, player1Score: 8, player2Score: 11)->assertOk();
        $context->recordSet($setup['game'], setNumber: 3, player1Score: 11, player2Score: 9)->assertOk();

        $response = $context->recordSet($setup['game'], setNumber: 4, player1Score: 11, player2Score: 6);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', GameStatus::Finished->value)
            ->assertJsonPath('data.sets_won.player1', 3);
    }

    public function test_record_set_uses_game_snapshot_for_best_of_seven(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 2);

        $setup['game']->update([
            'best_of' => 7,
            'sets_to_win' => 4,
        ]);

        for ($setNumber = 1; $setNumber <= 3; $setNumber++) {
            $context->recordSet($setup['game'], setNumber: $setNumber, player1Score: 11, player2Score: 5)->assertOk();
        }

        $response = $context->recordSet($setup['game'], setNumber: 4, player1Score: 11, player2Score: 6);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', GameStatus::Finished->value)
            ->assertJsonPath('data.sets_won.player1', 4);
    }

    public function test_record_set_rejects_set_number_above_best_of(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 2);

        $setup['game']->update([
            'best_of' => 3,
            'sets_to_win' => 2,
        ]);

        $response = $context->recordSet($setup['game'], setNumber: 4, player1Score: 11, player2Score: 5);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['set_number']);
    }

    public function test_allows_updating_competition_best_of_when_only_pending_games_exist(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $response = $context->updateCompetitionViaApi($setup['competition'], [
            'group_stage_best_of' => 7,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.group_stage_best_of', 7);
    }

    public function test_blocks_updating_competition_best_of_when_real_activity_started(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();
        $context->finishGame($setup['game'], $setup['playerOne'])->assertOk();

        $response = $context->updateCompetitionViaApi($setup['competition'], [
            'group_stage_best_of' => 7,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group_stage_best_of']);
    }

    public function test_changing_competition_format_does_not_change_existing_game_snapshot(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition(setsToWin: 2);
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $game = Game::query()->where('group_id', $group->id)->sole();
        $originalBestOf = $game->best_of;
        $originalSetsToWin = $game->sets_to_win;

        $competition->update([
            'group_stage_best_of' => 7,
            'knockout_stage_best_of' => 7,
            'semifinal_best_of' => 7,
            'final_best_of' => 7,
        ]);

        $game->refresh();

        $this->assertSame($originalBestOf, $game->best_of);
        $this->assertSame($originalSetsToWin, $game->sets_to_win);
    }
}
