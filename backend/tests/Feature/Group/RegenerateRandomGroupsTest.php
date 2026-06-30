<?php

namespace Tests\Feature\Group;

use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupPlayer;
use App\Models\Registration;
use App\Support\Competition\CompetitionStructureGuard;
use Tests\TestCase;

class RegenerateRandomGroupsTest extends TestCase
{
    public function test_regenerates_groups_and_games_when_everything_is_pending(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $originalGroupIds = Group::query()
            ->where('competition_id', $competition->id)
            ->pluck('id')
            ->all();

        $response = $context->regenerateRandomGroups($competition, groupsCount: 2);

        $response
            ->assertCreated()
            ->assertJson([
                'message' => 'Grupos regenerados correctamente.',
                'groups_removed' => 2,
                'games_removed' => 6,
                'bracket_removed' => false,
                'groups_created' => 2,
                'players_assigned' => 6,
                'games_created' => 6,
            ])
            ->assertJsonCount(2, 'groups');

        $newGroupIds = Group::query()
            ->where('competition_id', $competition->id)
            ->pluck('id')
            ->all();

        $this->assertNotEquals($originalGroupIds, $newGroupIds);
        $this->assertDatabaseCount('groups', 2);
        $this->assertDatabaseCount('group_players', 6);
        $this->assertSame(6, Game::query()->where('competition_id', $competition->id)->count());
    }

    public function test_regenerates_including_late_registered_player(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        [$latePlayer] = $context->createPlayers(1);
        $context->registerPlayer($competition, $latePlayer);

        $response = $context->regenerateRandomGroups($competition, groupsCount: 2);

        $response
            ->assertCreated()
            ->assertJsonPath('players_assigned', 5);

        $assignedPlayerIds = GroupPlayer::query()
            ->whereHas('group', fn ($query) => $query->where('competition_id', $competition->id))
            ->pluck('player_id')
            ->sort()
            ->values()
            ->all();

        $expectedPlayerIds = collect([...$players, $latePlayer])
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expectedPlayerIds, $assignedPlayerIds);
    }

    public function test_rejects_when_real_game_is_in_progress(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $game = Game::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('group_id')
            ->firstOrFail();

        $context->recordSet($game, setNumber: 1, player1Score: 11, player2Score: 5)
            ->assertOk();

        $response = $context->regenerateRandomGroups($competition, groupsCount: 2);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', CompetitionStructureGuard::LOCK_MESSAGE);
    }

    public function test_rejects_when_real_game_is_finished(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $game = Game::query()
            ->where('competition_id', $competition->id)
            ->whereNotNull('group_id')
            ->firstOrFail();

        $winner = $game->player1;
        $context->finishGame($game, $winner)->assertOk();

        $response = $context->regenerateRandomGroups($competition, groupsCount: 2);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', CompetitionStructureGuard::LOCK_MESSAGE);
    }

    public function test_allows_regeneration_with_finished_byes_and_removes_bracket(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $bracket = Bracket::query()->create([
            'competition_id' => $competition->id,
            'name' => 'Eliminatoria',
            'qualifiers_per_group' => 2,
            'bracket_size' => 4,
            'byes_count' => 1,
        ]);

        Game::query()->create([
            'competition_id' => $competition->id,
            'bracket_id' => $bracket->id,
            'player1_id' => $players[0]->id,
            'player2_id' => null,
            'round' => 'Ronda clasificatoria',
            'status' => GameStatus::Finished,
            'is_bye' => true,
            'bracket_round' => 1,
            'bracket_match' => 1,
            'best_of' => 5,
            'sets_to_win' => 3,
        ]);

        Game::query()->create([
            'competition_id' => $competition->id,
            'bracket_id' => $bracket->id,
            'player1_id' => $players[1]->id,
            'player2_id' => $players[2]->id,
            'round' => 'Ronda clasificatoria',
            'status' => GameStatus::Pending,
            'is_bye' => false,
            'bracket_round' => 1,
            'bracket_match' => 2,
            'best_of' => 5,
            'sets_to_win' => 3,
        ]);

        $response = $context->regenerateRandomGroups($competition, groupsCount: 2);

        $response
            ->assertCreated()
            ->assertJsonPath('bracket_removed', true);

        $this->assertDatabaseCount('brackets', 0);
        $this->assertSame(
            0,
            Game::query()->where('competition_id', $competition->id)->whereNotNull('bracket_id')->count(),
        );
    }

    public function test_does_not_leave_orphan_games_with_null_group_or_bracket_references(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $bracket = Bracket::query()->create([
            'competition_id' => $competition->id,
            'name' => 'Eliminatoria',
            'qualifiers_per_group' => 2,
        ]);

        Game::query()->create([
            'competition_id' => $competition->id,
            'bracket_id' => $bracket->id,
            'player1_id' => $players[0]->id,
            'player2_id' => null,
            'round' => 'Ronda clasificatoria',
            'status' => GameStatus::Finished,
            'is_bye' => true,
            'best_of' => 5,
            'sets_to_win' => 3,
        ]);

        $context->regenerateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $this->assertSame(
            0,
            Game::query()
                ->where('competition_id', $competition->id)
                ->whereNull('group_id')
                ->whereNull('bracket_id')
                ->count(),
        );

        $this->assertSame(
            0,
            Game::query()
                ->where('competition_id', $competition->id)
                ->whereNull('group_id')
                ->whereNotNull('bracket_id')
                ->count(),
        );
    }

    public function test_does_not_delete_registrations(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $registrationCountBefore = Registration::query()
            ->where('competition_id', $competition->id)
            ->count();

        $context->regenerateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $this->assertSame(
            $registrationCountBefore,
            Registration::query()->where('competition_id', $competition->id)->count(),
        );
    }

    public function test_response_includes_expected_summary_fields(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $response = $context->regenerateRandomGroups($competition, groupsCount: 2);

        $response
            ->assertCreated()
            ->assertJsonStructure([
                'message',
                'groups_removed',
                'games_removed',
                'bracket_removed',
                'groups_created',
                'players_assigned',
                'games_created',
                'groups' => [
                    '*' => [
                        'id',
                        'competition_id',
                        'name',
                        'group_players',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }

    public function test_rejects_when_competition_has_no_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $response = $context->regenerateRandomGroups($competition, groupsCount: 2);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', 'La competencia no tiene grupos para regenerar.');
    }

    public function test_rolls_back_when_generation_fails(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $originalGroupIds = Group::query()
            ->where('competition_id', $competition->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $response = $context->regenerateRandomGroups($competition, groupsCount: 99);

        $response->assertUnprocessable();

        $currentGroupIds = Group::query()
            ->where('competition_id', $competition->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame($originalGroupIds, $currentGroupIds);
    }
}
