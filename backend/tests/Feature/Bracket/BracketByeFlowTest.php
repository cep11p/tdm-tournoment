<?php

namespace Tests\Feature\Bracket;

use App\Models\Game;
use App\Models\Player;
use Tests\TestCase;

class BracketByeFlowTest extends TestCase
{
    public function test_creates_q3_bracket_with_padding_bye_for_single_group(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        $group = $context->createGroupWithPlayers($competition, $players, 'Grupo A');
        $context->generateRoundRobin($group)->assertCreated();
        $this->finishGroupRoundRobinWithRankOrder($group->id, $players);

        $response = $context->createBracket($competition);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 4)
            ->assertJsonPath('data.byes_count', 1);

        $this->assertDatabaseCount('brackets', 1);
    }

    public function test_creates_bracket_of_sixteen_with_four_byes_for_twelve_qualifiers(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(12);
        $context->registerPlayers($competition, $players);
        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        $groups = [
            $context->createGroupWithPlayers($competition, array_slice($players, 0, 3), 'Grupo A'),
            $context->createGroupWithPlayers($competition, array_slice($players, 3, 3), 'Grupo B'),
            $context->createGroupWithPlayers($competition, array_slice($players, 6, 3), 'Grupo C'),
            $context->createGroupWithPlayers($competition, array_slice($players, 9, 3), 'Grupo D'),
        ];

        foreach ($groups as $group) {
            $context->generateRoundRobin($group)->assertCreated();
            $groupPlayers = $group->groupPlayers()->with('player')->get()->pluck('player')->all();
            $this->finishGroupRoundRobinWithRankOrder($group->id, $groupPlayers);
        }

        $response = $context->createBracket($competition);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 16)
            ->assertJsonPath('data.byes_count', 4)
            ->assertJsonCount(8, 'data.games');

        $byeGames = collect($response->json('data.games'))
            ->filter(fn (array $game): bool => ($game['is_bye'] ?? false) === true)
            ->sortBy('bracket_match')
            ->values();

        $this->assertCount(4, $byeGames);
        $this->assertSame($players[0]->id, $byeGames[0]['player1']['id']);
        $this->assertSame('Ronda clasificatoria', $response->json('data.games.0.round'));
    }

    public function test_creates_bracket_of_thirty_two_with_eight_byes_for_twenty_four_qualifiers(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(24);
        $context->registerPlayers($competition, $players);
        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        $groupNames = ['Grupo A', 'Grupo B', 'Grupo C', 'Grupo D', 'Grupo E', 'Grupo F', 'Grupo G', 'Grupo H'];

        foreach ($groupNames as $index => $groupName) {
            $groupPlayers = array_slice($players, $index * 3, 3);
            $group = $context->createGroupWithPlayers($competition, $groupPlayers, $groupName);
            $context->generateRoundRobin($group)->assertCreated();
            $this->finishGroupRoundRobinWithRankOrder($group->id, $groupPlayers);
        }

        $response = $context->createBracket($competition);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 32)
            ->assertJsonPath('data.byes_count', 8)
            ->assertJsonCount(16, 'data.games');

        $byeGames = collect($response->json('data.games'))
            ->filter(fn (array $game): bool => ($game['is_bye'] ?? false) === true)
            ->sortBy('bracket_match')
            ->values();

        $this->assertCount(8, $byeGames);
        $this->assertSame('Ronda clasificatoria', $byeGames[0]['round']);
        $this->assertSame('finished', $byeGames[0]['status']);
    }

    public function test_generates_next_round_after_byes_and_finished_real_games(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(12);
        $context->registerPlayers($competition, $players);
        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        $groups = [
            $context->createGroupWithPlayers($competition, array_slice($players, 0, 3), 'Grupo A'),
            $context->createGroupWithPlayers($competition, array_slice($players, 3, 3), 'Grupo B'),
            $context->createGroupWithPlayers($competition, array_slice($players, 6, 3), 'Grupo C'),
            $context->createGroupWithPlayers($competition, array_slice($players, 9, 3), 'Grupo D'),
        ];

        foreach ($groups as $group) {
            $context->generateRoundRobin($group)->assertCreated();
            $groupPlayers = $group->groupPlayers()->with('player')->get()->pluck('player')->all();
            $this->finishGroupRoundRobinWithRankOrder($group->id, $groupPlayers);
        }

        $context->createBracket($competition)->assertCreated();

        $bracket = $competition->fresh()->brackets()->firstOrFail();
        $firstRoundGames = $context->bracketGamesForRound($bracket, 1);

        foreach ($firstRoundGames->reject(fn (Game $game): bool => $game->is_bye) as $playInGame) {
            $context->finishGame($playInGame, $playInGame->player1)->assertOk();
        }

        $response = $context->generateBracketNextRound($bracket);

        $response->assertCreated();

        $secondRoundGames = $context->bracketGamesForRound($bracket->fresh(), 2);
        $this->assertCount(4, $secondRoundGames);
        $this->assertSame('Cuartos de final', $secondRoundGames[0]->round);
        $this->assertSame($players[0]->id, $secondRoundGames[0]->player1_id);
    }

    public function test_rejects_bracket_when_fewer_than_two_qualifiers(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $competition->update(['qualified_per_group' => 1]);
        $competition->refresh();

        $group = $context->createGroupWithPlayers($competition, $players, 'Grupo A');
        $context->generateRoundRobin($group)->assertCreated();

        $game = Game::query()->where('group_id', $group->id)->sole();
        $context->finishGame($game, $players[0])->assertOk();

        $response = $context->createBracket($competition);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['qualified_per_group']);

        $this->assertDatabaseCount('brackets', 0);
    }

    /**
     * @param  array<int, Player>  $playersInRankOrder
     */
    private function finishGroupRoundRobinWithRankOrder(int $groupId, array $playersInRankOrder): void
    {
        $context = $this->tournamentContext();
        $games = Game::query()->where('group_id', $groupId)->get();

        for ($index = 0; $index < count($playersInRankOrder); $index++) {
            for ($pairIndex = $index + 1; $pairIndex < count($playersInRankOrder); $pairIndex++) {
                $winner = $playersInRankOrder[$index];
                $left = $playersInRankOrder[$index];
                $right = $playersInRankOrder[$pairIndex];

                $game = $games->first(
                    fn (Game $candidate): bool => (
                        (int) $candidate->player1_id === $left->id && (int) $candidate->player2_id === $right->id
                    ) || (
                        (int) $candidate->player1_id === $right->id && (int) $candidate->player2_id === $left->id
                    )
                );

                $this->assertNotNull($game);
                $context->finishGame($game, $winner)->assertOk();
            }
        }
    }
}
