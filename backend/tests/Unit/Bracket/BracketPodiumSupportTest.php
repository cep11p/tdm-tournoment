<?php

namespace Tests\Unit\Bracket;

use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Models\Game;
use App\Support\Bracket\BracketPodiumSupport;
use Tests\TestCase;

class BracketPodiumSupportTest extends TestCase
{
    public function test_final_and_semifinal_round_numbers_for_eight_player_bracket(): void
    {
        $bracket = new Bracket([
            'bracket_size' => 8,
        ]);

        $this->assertSame(3, BracketPodiumSupport::finalRound($bracket));
        $this->assertSame(2, BracketPodiumSupport::semifinalRound($bracket));
    }

    public function test_semifinal_round_is_null_for_two_player_bracket(): void
    {
        $bracket = new Bracket([
            'bracket_size' => 2,
        ]);

        $this->assertSame(1, BracketPodiumSupport::finalRound($bracket));
        $this->assertNull(BracketPodiumSupport::semifinalRound($bracket));
        $this->assertFalse(BracketPodiumSupport::canDetermineThirdPlace($bracket));
    }

    public function test_three_player_bracket_is_not_eligible_for_shared_third_place(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = $competition->fresh()->brackets()->firstOrFail();

        $this->assertSame(4, $bracket->bracket_size);
        $this->assertFalse(BracketPodiumSupport::canDetermineThirdPlace($bracket));
        $this->assertSame([], BracketPodiumSupport::semifinalLosers($bracket));
    }

    public function test_four_player_bracket_is_eligible_after_semifinals_finish(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = $competition->fresh()->brackets()->firstOrFail();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($semifinals[0], $players[0])->assertOk();
        $context->finishGame($semifinals[1], $players[2])->assertOk();

        $bracket->refresh();

        $this->assertTrue(BracketPodiumSupport::canDetermineThirdPlace($bracket));

        $losers = BracketPodiumSupport::semifinalLosers($bracket);

        $this->assertCount(2, $losers);
        $this->assertSame($players[3]->id, $losers[0]->id);
        $this->assertSame($players[1]->id, $losers[1]->id);
    }
}
