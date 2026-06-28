<?php

namespace Tests\Feature\Bracket;

use App\Enums\GameStatus;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Player;
use Database\Seeders\DemoPendingByesSeeder;
use Tests\TestCase;

class BracketByeFlowTest extends TestCase
{
    public function test_creates_bracket_of_four_with_one_bye_for_three_qualifiers(): void
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
            ->assertJsonPath('data.byes_count', 1)
            ->assertJsonCount(2, 'data.games');

        $byeGames = collect($response->json('data.games'))
            ->filter(fn (array $game): bool => ($game['is_bye'] ?? false) === true);

        $this->assertCount(1, $byeGames);
        $this->assertSame($players[0]->id, $byeGames->first()['player1']['id']);
        $this->assertNull($byeGames->first()['player2']['id']);
        $this->assertSame('finished', $byeGames->first()['status']);
        $this->assertSame($players[0]->id, $byeGames->first()['winner_id']);
    }

    public function test_creates_bracket_of_eight_with_two_byes_for_six_qualifiers(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);
        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        $groups = [
            $context->createGroupWithPlayers($competition, array_slice($players, 0, 3), 'Grupo A'),
            $context->createGroupWithPlayers($competition, array_slice($players, 3, 3), 'Grupo B'),
        ];

        foreach ($groups as $group) {
            $context->generateRoundRobin($group)->assertCreated();
            $groupPlayers = $group->groupPlayers()->with('player')->get()->pluck('player')->all();
            $this->finishGroupRoundRobinWithRankOrder($group->id, $groupPlayers);
        }

        $response = $context->createBracket($competition);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 8)
            ->assertJsonPath('data.byes_count', 2)
            ->assertJsonCount(4, 'data.games');

        $byeGames = collect($response->json('data.games'))
            ->filter(fn (array $game): bool => ($game['is_bye'] ?? false) === true)
            ->sortBy('bracket_match')
            ->values();

        $this->assertCount(2, $byeGames);
        $this->assertSame($players[0]->id, $byeGames[0]['player1']['id']);
        $this->assertSame($players[3]->id, $byeGames[1]['player1']['id']);
        $this->assertSame('Cuartos de final', $response->json('data.games.0.round'));
    }

    public function test_creates_bracket_of_32_with_two_byes_for_demo_pending_byes_seeder(): void
    {
        $this->seed(DemoPendingByesSeeder::class);

        $competition = Competition::query()
            ->where('name', 'Singles Club')
            ->firstOrFail();

        $context = $this->tournamentContext();
        $response = $context->createBracket($competition);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 32)
            ->assertJsonPath('data.byes_count', 2)
            ->assertJsonCount(16, 'data.games');

        $byeGames = collect($response->json('data.games'))
            ->filter(fn (array $game): bool => ($game['is_bye'] ?? false) === true)
            ->sortBy('bracket_match')
            ->values();

        $this->assertCount(2, $byeGames);
        $this->assertSame('16avos de final', $byeGames[0]['round']);
        $this->assertSame('finished', $byeGames[0]['status']);
    }

    public function test_generates_next_round_after_byes_and_finished_real_games(): void
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

        $context->createBracket($competition)->assertCreated();

        $bracket = $competition->fresh()->brackets()->firstOrFail();
        $firstRoundGames = $context->bracketGamesForRound($bracket, 1);

        $realGame = $firstRoundGames->first(
            fn (Game $game): bool => ! $game->is_bye && $game->status !== GameStatus::Finished
        );

        $this->assertNotNull($realGame);

        $winner = $realGame->player1_id === $players[1]->id
            ? $players[1]
            : $players[2];

        $context->finishGame($realGame, $winner)->assertOk();

        $response = $context->generateBracketNextRound($bracket);

        $response->assertCreated();

        $finalGames = $context->bracketGamesForRound($bracket->fresh(), 2);
        $this->assertCount(1, $finalGames);
        $this->assertSame('Final', $finalGames[0]->round);
        $this->assertSame($players[0]->id, $finalGames[0]->player1_id);
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
