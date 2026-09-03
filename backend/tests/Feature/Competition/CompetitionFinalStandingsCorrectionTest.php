<?php

namespace Tests\Feature\Competition;

use App\Enums\BracketGamePurpose;
use App\Enums\ThirdPlaceMode;
use App\Models\Game;
use Tests\TestCase;

class CompetitionFinalStandingsCorrectionTest extends TestCase
{
    public function test_final_correction_swaps_first_and_second(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['admin']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $final = $competition->games()->where('round', 'Final')->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();
        $final = $final->fresh();

        $entryA = $context->entryIdFor($competition, $players[0]);
        $entryB = $context->entryIdFor($competition, $players[1]);

        $this->assertSame(1, (int) $competition->finalStandings()->where('competition_entry_id', $entryA)->value('position'));
        $this->assertSame(2, (int) $competition->finalStandings()->where('competition_entry_id', $entryB)->value('position'));

        $player1Won = (int) $final->winner_entry_id === (int) $final->entry1_id;

        $context->correctResult($final, 'Corrección de la Final', [
            $player1Won
                ? ['player1_score' => 0, 'player2_score' => 11]
                : ['player1_score' => 11, 'player2_score' => 0],
        ])->assertOk();

        $this->assertSame(1, (int) $competition->finalStandings()->where('competition_entry_id', $entryB)->value('position'));
        $this->assertSame(2, (int) $competition->finalStandings()->where('competition_entry_id', $entryA)->value('position'));
    }

    public function test_third_place_correction_swaps_third_and_fourth(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['admin']));

        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Playoff]);
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = $competition->brackets()->firstOrFail();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        foreach ($semifinals as $game) {
            $context->finishGame($game, $game->singlesPlayer1())->assertOk();
        }

        $context->generateBracketNextRound($bracket)->assertCreated();
        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->sole();
        $context->finishGame($final, $final->singlesPlayer1())->assertOk();

        $thirdPlace = Game::query()
            ->where('bracket_id', $bracket->id)
            ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
            ->firstOrFail();

        $context->finishGame($thirdPlace, $thirdPlace->singlesPlayer1())->assertOk();

        $thirdEntryId = (int) $thirdPlace->fresh()->winner_entry_id;
        $fourthEntryId = (int) $thirdPlace->fresh()->winner_entry_id === (int) $thirdPlace->entry1_id
            ? (int) $thirdPlace->entry2_id
            : (int) $thirdPlace->entry1_id;

        $this->assertSame(3, (int) $competition->finalStandings()->where('competition_entry_id', $thirdEntryId)->value('position'));
        $this->assertSame(4, (int) $competition->finalStandings()->where('competition_entry_id', $fourthEntryId)->value('position'));

        $player1WonOriginally = (int) $thirdPlace->fresh()->winner_entry_id === (int) $thirdPlace->entry1_id;

        $context->correctResult($thirdPlace->fresh(), 'Corrección del tercer puesto', [
            $player1WonOriginally
                ? ['player1_score' => 0, 'player2_score' => 11]
                : ['player1_score' => 11, 'player2_score' => 0],
        ])->assertOk();

        $this->assertSame(3, (int) $competition->finalStandings()->where('competition_entry_id', $fourthEntryId)->value('position'));
        $this->assertSame(4, (int) $competition->finalStandings()->where('competition_entry_id', $thirdEntryId)->value('position'));
    }
}
