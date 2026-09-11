<?php

namespace Tests\Feature\Group;

use App\Enums\CompetitionFormat;
use App\Enums\GameStatus;
use App\Enums\TournamentStatus;
use App\Models\Game;
use App\Models\Group;
use App\Support\Competition\CompetitionEntryGuard;
use App\Support\Competition\CompetitionStructureGuard;
use App\Support\Competition\LateGroupMutationGuard;
use App\Support\Competition\TeamCompetitionStructureGuard;
use App\Support\Tournament\TournamentLifecycleGuard;
use Tests\TestCase;

class LateGroupMutationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_allows_late_registration_when_group_games_are_finished_and_bracket_was_not_generated(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);

        $response = $context->registerPlayerViaApi(
            $setup['competition'],
            $context->createPlayers(1)[0],
        );

        $response->assertCreated();
        $this->assertDatabaseCount('competition_entries', 5);

        $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"))
            ->assertOk()
            ->assertJsonPath('data.is_registrations_editable', true)
            ->assertJsonPath('data.is_structure_editable', false)
            ->assertJsonPath('data.structure_lock_reason', CompetitionStructureGuard::LOCK_MESSAGE);
    }

    public function test_allows_creating_a_late_group_when_other_group_games_are_finished(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);
        $gamesBefore = Game::query()->where('competition_id', $setup['competition']->id)->count();

        $response = $context->createGroupViaApi($setup['competition'], 'Grupo D');

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Grupo D');

        $this->assertDatabaseHas('groups', [
            'competition_id' => $setup['competition']->id,
            'name' => 'Grupo D',
        ]);
        $this->assertSame(
            $gamesBefore,
            Game::query()->where('competition_id', $setup['competition']->id)->count(),
        );
    }

    public function test_allows_assigning_a_late_entry_to_an_existing_group_with_finished_games(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);
        [$latePlayer] = $context->createPlayers(1);
        $context->registerPlayerViaApi($setup['competition'], $latePlayer)->assertCreated();
        $gamesBefore = Game::query()->where('competition_id', $setup['competition']->id)->count();

        $response = $context->assignPlayerToGroupViaApi($setup['groupA'], $latePlayer);

        $response->assertCreated();
        $this->assertDatabaseCount('group_entries', 5);
        $this->assertSame(3, $setup['groupA']->groupEntries()->count());
        $this->assertSame(
            $gamesBefore,
            Game::query()->where('competition_id', $setup['competition']->id)->count(),
        );
    }

    public function test_allows_assigning_a_late_entry_to_a_newly_created_group(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);
        [$latePlayer] = $context->createPlayers(1);
        $context->registerPlayerViaApi($setup['competition'], $latePlayer)->assertCreated();
        $groupD = Group::query()->findOrFail(
            $context->createGroupViaApi($setup['competition'], 'Grupo D')->json('data.id'),
        );

        $context->assignPlayerToGroupViaApi($groupD, $latePlayer)->assertCreated();

        $this->assertSame(1, $groupD->groupEntries()->count());
        $this->assertSame(
            1,
            Game::query()->where('group_id', $setup['groupA']->id)->count(),
        );
        $this->assertSame(0, Game::query()->where('group_id', $groupD->id)->count());
    }

    public function test_blocks_late_registration_create_group_and_assign_when_bracket_exists(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);
        [$latePlayer] = $context->createPlayers(1);
        $context->registerPlayerViaApi($setup['competition'], $latePlayer)->assertCreated();
        $context->createBracket($setup['competition'])->assertCreated();
        [$anotherLatePlayer] = $context->createPlayers(1);

        $context->registerPlayerViaApi($setup['competition'], $anotherLatePlayer)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', CompetitionEntryGuard::LOCK_MESSAGE);

        $context->createGroupViaApi($setup['competition'], 'Grupo D')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', LateGroupMutationGuard::LOCK_MESSAGE);

        $context->assignPlayerToGroupViaApi($setup['groupA'], $latePlayer)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', LateGroupMutationGuard::LOCK_MESSAGE);
    }

    public function test_blocks_late_registration_create_group_and_assign_when_tournament_is_finished(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);
        [$latePlayer] = $context->createPlayers(1);
        $context->registerPlayerViaApi($setup['competition'], $latePlayer)->assertCreated();
        $setup['competition']->tournament->update([
            'status' => TournamentStatus::Finished,
            'closed_at' => now(),
        ]);
        [$anotherLatePlayer] = $context->createPlayers(1);

        $context->registerPlayerViaApi($setup['competition'], $anotherLatePlayer)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament'])
            ->assertJsonPath('errors.tournament.0', TournamentLifecycleGuard::LOCK_MESSAGE);

        $context->createGroupViaApi($setup['competition'], 'Grupo D')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament'])
            ->assertJsonPath('errors.tournament.0', TournamentLifecycleGuard::LOCK_MESSAGE);

        $context->assignPlayerToGroupViaApi($setup['groupA'], $latePlayer)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament'])
            ->assertJsonPath('errors.tournament.0', TournamentLifecycleGuard::LOCK_MESSAGE);
    }

    public function test_regenerate_groups_remains_blocked_when_group_games_are_finished(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);

        $context->regenerateRandomGroups($setup['competition'], groupsCount: 2)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', CompetitionStructureGuard::LOCK_MESSAGE);
    }

    public function test_structural_competition_updates_remain_blocked_when_group_games_are_finished(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);

        $context->updateCompetitionViaApi($setup['competition'], [
            'format' => CompetitionFormat::KnockoutDirect->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['format'])
            ->assertJsonPath('errors.format.0', CompetitionStructureGuard::LOCK_MESSAGE);

        $context->updateCompetitionViaApi($setup['competition'], [
            'qualified_per_group' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['qualified_per_group'])
            ->assertJsonPath('errors.qualified_per_group.0', CompetitionStructureGuard::LOCK_MESSAGE);

        $context->updateCompetitionViaApi($setup['competition'], [
            'group_stage_best_of' => 5,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group_stage_best_of'])
            ->assertJsonPath('errors.group_stage_best_of.0', CompetitionStructureGuard::LOCK_MESSAGE);
    }

    public function test_team_registration_and_group_mutation_rules_are_not_relaxed(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 3, 4);
        $group = $context->createGroupWithEntries($competition, [$entries[0], $entries[1]]);
        $context->generateTeamRoundRobin($group)->assertCreated();

        $extraPlayers = $context->createPlayers(4);
        $context->registerTeamViaApi(
            $competition,
            'Equipo extra',
            array_map(static fn ($player): int => $player->id, $extraPlayers),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.competition.0', TeamCompetitionStructureGuard::REGISTRATIONS_LOCK_MESSAGE);

        $rubber = Game::query()
            ->where('competition_id', $competition->id)
            ->where(function ($query): void {
                $query->where('is_bye', false)->orWhereNull('is_bye');
            })
            ->firstOrFail();
        $rubber->update(['status' => GameStatus::Finished]);

        $context->createGroupViaApi($competition, 'Grupo B')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', CompetitionStructureGuard::LOCK_MESSAGE);

        $context->assignEntryToGroupViaApi($group, $entries[2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', CompetitionStructureGuard::LOCK_MESSAGE);
    }

    public function test_cannot_assign_entry_from_another_competition_after_group_games_are_finished(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);
        $otherCompetition = $context->createCompetition();
        [$otherPlayer] = $context->createPlayers(1);
        $context->registerPlayer($otherCompetition, $otherPlayer);

        $response = $context->assignPlayerToGroupViaApi($setup['groupA'], $otherPlayer);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);
        $this->assertDatabaseCount('group_entries', 4);
    }

    public function test_cannot_assign_an_already_assigned_entry_after_group_games_are_finished(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);

        $context->assignPlayerToGroupViaApi($setup['groupA'], $setup['playerOne'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);

        $context->assignPlayerToGroupViaApi($setup['groupB'], $setup['playerOne'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);

        $this->assertDatabaseCount('group_entries', 4);
    }
}
