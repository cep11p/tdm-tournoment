<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionFormat;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Support\Competition\CompetitionStructureGuard;
use App\Support\Competition\RegistrationGuard;
use Tests\TestCase;

class CompetitionStructureEditableTest extends TestCase
{
    public function test_competition_without_games_is_editable(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $response = $context->updateCompetitionViaApi($competition, []);

        $response
            ->assertOk()
            ->assertJsonPath('data.is_structure_editable', true)
            ->assertJsonPath('data.structure_lock_reason', null);
    }

    public function test_competition_with_only_pending_games_is_editable(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $response = $context->updateCompetitionViaApi($competition, [
            'group_stage_best_of' => 7,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.is_structure_editable', true)
            ->assertJsonPath('data.structure_lock_reason', null)
            ->assertJsonPath('data.group_stage_best_of', 7);
    }

    public function test_competition_with_groups_and_pending_fixture_is_editable(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $response = $context->registerPlayerViaApi($competition, $context->createPlayers(1)[0]);

        $response
            ->assertCreated();

        $showResponse = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $showResponse
            ->assertOk()
            ->assertJsonPath('data.is_structure_editable', true)
            ->assertJsonPath('data.structure_lock_reason', null);
    }

    public function test_competition_with_finished_byes_and_pending_real_games_is_editable(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);

        $context->createBracket($competition)->assertCreated();

        $this->assertTrue(
            Game::query()
                ->where('competition_id', $competition->id)
                ->where('is_bye', true)
                ->where('status', GameStatus::Finished)
                ->exists()
        );

        $this->assertTrue(
            Game::query()
                ->where('competition_id', $competition->id)
                ->where(function ($query): void {
                    $query->where('is_bye', false)->orWhereNull('is_bye');
                })
                ->where('status', GameStatus::Pending)
                ->exists()
        );

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.is_structure_editable', true)
            ->assertJsonPath('data.structure_lock_reason', null)
            ->assertJsonPath('data.is_registrations_editable', false)
            ->assertJsonPath('data.registrations_lock_reason', RegistrationGuard::LOCK_MESSAGE);
    }

    public function test_competition_with_bye_game_in_progress_does_not_block_structure(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $byeGame = Game::query()
            ->where('competition_id', $competition->id)
            ->where('is_bye', true)
            ->firstOrFail();
        $byeGame->update(['status' => GameStatus::InProgress]);

        $this->assertTrue(CompetitionStructureGuard::isStructureEditable($competition->fresh()));

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.is_structure_editable', true)
            ->assertJsonPath('data.structure_lock_reason', null);
    }

    public function test_competition_with_in_progress_non_bye_game_is_locked(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 5)
            ->assertOk();

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.is_structure_editable', false)
            ->assertJsonPath('data.structure_lock_reason', CompetitionStructureGuard::LOCK_MESSAGE);

        $registerResponse = $context->registerPlayerViaApi(
            $setup['competition'],
            $context->createPlayers(1)[0],
        );

        $registerResponse
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', CompetitionStructureGuard::LOCK_MESSAGE);
    }

    public function test_competition_with_finished_non_bye_game_is_locked(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $context->finishGame($setup['game'], $setup['playerOne'])->assertOk();

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.is_structure_editable', false)
            ->assertJsonPath('data.structure_lock_reason', CompetitionStructureGuard::LOCK_MESSAGE);
    }

    public function test_name_and_category_remain_editable_when_structure_is_locked(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();
        $context->finishGame($setup['game'], $setup['playerOne'])->assertOk();

        $response = $context->updateCompetitionViaApi($setup['competition'], [
            'name' => 'Nombre actualizado',
            'category' => 'segunda',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.name', 'Nombre actualizado')
            ->assertJsonPath('data.category', 'segunda')
            ->assertJsonPath('data.is_structure_editable', false);
    }

    public function test_blocks_structural_field_updates_when_structure_is_locked(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();
        $context->finishGame($setup['game'], $setup['playerOne'])->assertOk();

        $response = $context->updateCompetitionViaApi($setup['competition'], [
            'format' => CompetitionFormat::KnockoutDirect->value,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['format'])
            ->assertJsonPath('errors.format.0', CompetitionStructureGuard::LOCK_MESSAGE);
    }
}
