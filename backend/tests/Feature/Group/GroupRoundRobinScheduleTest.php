<?php

namespace Tests\Feature\Group;

use App\Models\Game;
use Tests\TestCase;

class GroupRoundRobinScheduleTest extends TestCase
{
    public function test_even_group_generates_correct_game_count(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();

        $this->assertSame(6, Game::query()->where('group_id', $group->id)->count());
    }

    public function test_odd_group_generates_correct_game_count_without_bye_games(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(5);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()->where('group_id', $group->id)->get();

        $this->assertSame(10, $games->count());
        $this->assertTrue($games->every(fn (Game $game): bool => ! $game->is_bye));
    }

    public function test_no_player_appears_more_than_once_in_the_same_round(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(5);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()
            ->where('group_id', $group->id)
            ->orderBy('group_round')
            ->orderBy('group_match')
            ->get();

        $gamesByRound = $games->groupBy('group_round');

        foreach ($gamesByRound as $roundGames) {
            $playersInRound = [];

            foreach ($roundGames as $game) {
                $playersInRound[] = (int) $game->player1_id;
                $playersInRound[] = (int) $game->player2_id;
            }

            $this->assertSame(
                count($playersInRound),
                count(array_unique($playersInRound)),
                'Un jugador apareció más de una vez en la misma ronda.',
            );
        }
    }

    public function test_generated_games_have_group_round_and_group_match(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $response = $context->generateRoundRobin($group)->assertCreated();

        $response->assertJsonPath('data.0.group_round', 1);
        $response->assertJsonPath('data.0.group_match', 1);

        $games = Game::query()->where('group_id', $group->id)->get();

        $this->assertTrue($games->every(
            fn (Game $game): bool => $game->group_round !== null && $game->group_match !== null,
        ));
    }

    public function test_round_robin_does_not_duplicate_pairings(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()->where('group_id', $group->id)->get();
        $pairings = $games->map(function (Game $game): string {
            $playerIds = [(int) $game->player1_id, (int) $game->player2_id];
            sort($playerIds);

            return implode('-', $playerIds);
        });

        $this->assertSame($pairings->count(), $pairings->unique()->count());
        $this->assertSame(15, $pairings->count());
    }

    public function test_round_robin_response_is_ordered_by_group_round_and_group_match(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $response = $context->generateRoundRobin($group)->assertCreated();
        $payload = collect($response->json('data'));

        $sortedPayload = $payload
            ->sortBy([
                ['group_round', 'asc'],
                ['group_match', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $this->assertSame(
            $sortedPayload->pluck('id')->all(),
            $payload->pluck('id')->all(),
        );
    }
}
