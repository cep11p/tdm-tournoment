<?php

namespace Tests\Feature\Bracket;

use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Support\Collection;
use Tests\TestCase;

class BracketStandardSeedingTest extends TestCase
{
    public function test_five_players_place_byes_on_seeds_one_two_and_three(): void
    {
        [$context, $players, $bracket] = $this->createDirectBracket(5);

        $this->assertSame(8, $bracket->bracket_size);
        $this->assertSame(3, $bracket->byes_count);

        $roundOne = $context->bracketGamesForRound($bracket, 1);

        $this->assertRoundSeedPairings($roundOne, $players, [
            [1, null],
            [4, 5],
            [2, null],
            [3, null],
        ]);

        $context->finishGame($roundOne[1], $players[3])->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $semifinals = $context->bracketGamesForRound($bracket->fresh(), 2);

        $this->assertRoundSeedPairings($semifinals, $players, [
            [1, 4],
            [2, 3],
        ]);
        $this->assertNotSame($players[0]->id, $semifinals[0]->singlesPlayer2Id());
        $this->assertNotSame($players[1]->id, $semifinals[0]->singlesPlayer2Id());
    }

    public function test_six_players_give_byes_to_seeds_one_and_two(): void
    {
        [$context, $players, $bracket] = $this->createDirectBracket(6);

        $this->assertSame(8, $bracket->bracket_size);
        $this->assertSame(2, $bracket->byes_count);

        $this->assertRoundSeedPairings($context->bracketGamesForRound($bracket, 1), $players, [
            [1, null],
            [4, 5],
            [2, null],
            [3, 6],
        ]);
    }

    public function test_seven_players_give_only_seed_one_a_bye(): void
    {
        [$context, $players, $bracket] = $this->createDirectBracket(7);

        $this->assertSame(8, $bracket->bracket_size);
        $this->assertSame(1, $bracket->byes_count);

        $this->assertRoundSeedPairings($context->bracketGamesForRound($bracket, 1), $players, [
            [1, null],
            [4, 5],
            [2, 7],
            [3, 6],
        ]);
    }

    public function test_eight_players_use_standard_quarters_without_byes(): void
    {
        [$context, $players, $bracket] = $this->createDirectBracket(8);

        $this->assertSame(8, $bracket->bracket_size);
        $this->assertSame(0, $bracket->byes_count);

        $this->assertRoundSeedPairings($context->bracketGamesForRound($bracket, 1), $players, [
            [1, 8],
            [4, 5],
            [2, 7],
            [3, 6],
        ]);
    }

    public function test_eight_players_advance_from_quarters_to_final_with_standard_halves(): void
    {
        [$context, $players, $bracket] = $this->createDirectBracket(8);

        $quarters = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($quarters[0], $players[0])->assertOk();
        $context->finishGame($quarters[1], $players[3])->assertOk();
        $context->finishGame($quarters[2], $players[1])->assertOk();
        $context->finishGame($quarters[3], $players[2])->assertOk();

        $context->generateBracketNextRound($bracket)->assertCreated();

        $semifinals = $context->bracketGamesForRound($bracket->fresh(), 2);

        $this->assertRoundSeedPairings($semifinals, $players, [
            [1, 4],
            [2, 3],
        ]);

        $context->finishGame($semifinals[0], $players[0])->assertOk();
        $context->finishGame($semifinals[1], $players[1])->assertOk();

        $context->generateBracketNextRound($bracket->fresh())->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 3);

        $this->assertRoundSeedPairings($final, $players, [
            [1, 2],
        ]);
        $this->assertSame('Final', $final[0]->round);
    }

    public function test_nine_players_keep_seeds_one_through_four_in_distinct_quarters(): void
    {
        [$context, $players, $bracket] = $this->createDirectBracket(9);

        $this->assertSame(16, $bracket->bracket_size);
        $this->assertSame(7, $bracket->byes_count);

        $roundOne = $context->bracketGamesForRound($bracket, 1);

        $this->assertRoundSeedPairings($roundOne, $players, [
            [1, null],
            [8, 9],
            [4, null],
            [5, null],
            [2, null],
            [7, null],
            [3, null],
            [6, null],
        ]);

        $context->finishGame($roundOne[1], $players[7])->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $this->assertRoundSeedPairings($context->bracketGamesForRound($bracket->fresh(), 2), $players, [
            [1, 8],
            [4, 5],
            [2, 7],
            [3, 6],
        ]);
    }

    public function test_thirteen_players_give_byes_to_seeds_one_two_and_three(): void
    {
        [$context, $players, $bracket] = $this->createDirectBracket(13);

        $this->assertSame(16, $bracket->bracket_size);
        $this->assertSame(3, $bracket->byes_count);

        $this->assertRoundSeedPairings($context->bracketGamesForRound($bracket, 1), $players, [
            [1, null],
            [8, 9],
            [4, 13],
            [5, 12],
            [2, null],
            [7, 10],
            [3, null],
            [6, 11],
        ]);
    }

    public function test_seventeen_players_only_real_first_round_match_is_sixteen_versus_seventeen(): void
    {
        [$context, $players, $bracket] = $this->createDirectBracket(17);

        $this->assertSame(32, $bracket->bracket_size);
        $this->assertSame(15, $bracket->byes_count);

        $this->assertRoundSeedPairings($context->bracketGamesForRound($bracket, 1), $players, [
            [1, null],
            [16, 17],
            [8, null],
            [9, null],
            [4, null],
            [13, null],
            [5, null],
            [12, null],
            [2, null],
            [15, null],
            [7, null],
            [10, null],
            [3, null],
            [14, null],
            [6, null],
            [11, null],
        ]);
    }

    /**
     * @return array{0: \Tests\Support\TournamentTestContext, 1: list<Player>, 2: Bracket}
     */
    private function createDirectBracket(int $playerCount): array
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers($playerCount);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();

        return [$context, $players, $bracket];
    }

    /**
     * @param  Collection<int, Game>  $games
     * @param  list<Player>  $players
     * @param  list<array{0: int, 1: int|null}>  $expectedSeeds
     */
    private function assertRoundSeedPairings(Collection $games, array $players, array $expectedSeeds): void
    {
        $this->assertCount(count($expectedSeeds), $games);

        $seen = [];

        foreach ($expectedSeeds as $index => [$seed1, $seed2]) {
            $game = $games[$index];
            $player1 = $players[$seed1 - 1];

            $this->assertSame($index + 1, (int) $game->bracket_match);
            $this->assertSame($player1->id, $game->singlesPlayer1Id());
            $this->assertArrayNotHasKey($player1->id, $seen);
            $seen[$player1->id] = true;

            if ($seed2 === null) {
                $this->assertTrue($game->is_bye);
                $this->assertNull($game->singlesPlayer2Id());
                $this->assertSame($player1->id, $game->singlesWinnerId());
                $this->assertSame(GameStatus::Finished, $game->status);

                continue;
            }

            $player2 = $players[$seed2 - 1];

            $this->assertFalse($game->is_bye);
            $this->assertSame($player2->id, $game->singlesPlayer2Id());
            $this->assertNull($game->singlesWinnerId());
            $this->assertArrayNotHasKey($player2->id, $seen);
            $seen[$player2->id] = true;
        }
    }
}
