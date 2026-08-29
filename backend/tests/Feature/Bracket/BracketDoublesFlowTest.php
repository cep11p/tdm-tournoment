<?php

namespace Tests\Feature\Bracket;

use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Enums\ThirdPlaceMode;
use App\Models\Bracket;
use App\Models\CompetitionEntry;
use App\Models\Game;
use Tests\TestCase;

class BracketDoublesFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_direct_knockout_doubles_creates_bracket_with_entry_seeding(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesKnockoutDirectCompetition();
        $players = $context->createPlayers(8);
        $entries = $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
            [$players[6], $players[7]],
        ]);

        $orderedEntryIds = CompetitionEntry::query()
            ->where('competition_id', $competition->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $roundOne = $context->bracketGamesForRound($bracket, 1);

        $this->assertCount(2, $roundOne);
        $this->assertSame($orderedEntryIds[0], (int) $roundOne[0]->entry1_id);
        $this->assertSame($orderedEntryIds[3], (int) $roundOne[0]->entry2_id);
        $this->assertSame($orderedEntryIds[1], (int) $roundOne[1]->entry1_id);
        $this->assertSame($orderedEntryIds[2], (int) $roundOne[1]->entry2_id);
    }

    public function test_bye_doubles_propagates_full_pair_to_next_round(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesKnockoutDirectCompetition();
        $players = $context->createPlayers(6);
        $entries = $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
        ]);

        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $byeGame = Game::query()
            ->where('bracket_id', $bracket->id)
            ->where('is_bye', true)
            ->firstOrFail();

        $this->assertSame($entries[0]->id, $byeGame->entry1_id);
        $this->assertNull($byeGame->entry2_id);
        $this->assertSame($entries[0]->id, $byeGame->winner_entry_id);

        $roundOne = $context->bracketGamesForRound($bracket, 1)
            ->reject(fn (Game $game) => $game->is_bye);

        foreach ($roundOne as $game) {
            $context->finishGameByEntryViaApi($game, (int) $game->entry1_id)->assertOk();
        }

        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->first();
        $this->assertNotNull($final);
        $this->assertContains((int) $final->entry1_id, [$entries[0]->id, $entries[1]->id, $entries[2]->id]);
        $this->assertContains((int) $final->entry2_id, [$entries[0]->id, $entries[1]->id, $entries[2]->id]);
    }

    public function test_semifinal_to_final_doubles_uses_entry_ids(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesKnockoutDirectCompetition();
        $players = $context->createPlayers(8);
        $entries = $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
            [$players[6], $players[7]],
        ]);

        $context->createBracket($competition)->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();

        foreach ($context->bracketGamesForRound($bracket, 1) as $game) {
            if ($game->is_bye) {
                continue;
            }

            $context->finishGameByEntryViaApi($game, (int) $game->entry1_id)->assertOk();
        }

        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->sole();
        $this->assertContains((int) $final->entry1_id, array_map(fn ($e) => $e->id, $entries));
        $this->assertContains((int) $final->entry2_id, array_map(fn ($e) => $e->id, $entries));

        $this->getJson($context->apiUrl("games/{$final->id}"))
            ->assertOk()
            ->assertJsonPath('data.player1', null)
            ->assertJsonPath('data.side1.display_name', fn ($name) => is_string($name) && $name !== '');
    }

    public function test_third_place_game_doubles_created_with_pair_entries(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Playoff]);
        $players = $context->createPlayers(8);
        $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
            [$players[6], $players[7]],
        ]);

        $context->createBracket($competition)->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();

        foreach ($context->bracketGamesForRound($bracket, 1) as $game) {
            if ($game->is_bye) {
                continue;
            }

            $context->finishGameByEntryViaApi($game, (int) $game->entry1_id)->assertOk();
        }

        $context->generateBracketNextRound($bracket)->assertCreated();

        $thirdPlace = Game::query()
            ->where('bracket_id', $bracket->id)
            ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
            ->first();

        $this->assertNotNull($thirdPlace);
        $this->assertNotNull($thirdPlace->entry1_id);
        $this->assertNotNull($thirdPlace->entry2_id);

        $this->getJson($context->apiUrl("games/{$thirdPlace->id}"))
            ->assertOk()
            ->assertJsonPath('data.side1.competition_entry_id', $thirdPlace->entry1_id)
            ->assertJsonPath('data.side2.competition_entry_id', $thirdPlace->entry2_id)
            ->assertJsonPath('data.player1', null);
    }

    public function test_correction_propagates_winner_entry_in_doubles_bracket(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesKnockoutDirectCompetition();
        $players = $context->createPlayers(8);
        $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
            [$players[6], $players[7]],
        ]);

        $context->createBracket($competition)->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinal = $context->bracketGamesForRound($bracket, 1)->first();

        $entryOneId = (int) $semifinal->entry1_id;
        $entryTwoId = (int) $semifinal->entry2_id;

        $context->finishGameByEntryViaApi($semifinal, $entryOneId)->assertOk();

        foreach ($context->bracketGamesForRound($bracket, 1) as $game) {
            if ($game->id === $semifinal->id || $game->is_bye || $game->status === GameStatus::Finished) {
                continue;
            }

            $context->finishGameByEntryViaApi($game, (int) $game->entry1_id)->assertOk();
        }

        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->sole();
        $this->assertSame($entryOneId, (int) $final->entry1_id);

        $context->correctResult($semifinal->fresh(), 'correccion doubles bracket', [
            ['player1_score' => 0, 'player2_score' => 11],
        ])->assertOk()
            ->assertJsonPath('data.winner_entry_id', $entryTwoId);

        $this->assertSame($entryTwoId, (int) $final->fresh()->entry1_id);
    }

    public function test_bracket_from_group_qualifiers_doubles(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(8);
        $entries = $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
            [$players[6], $players[7]],
        ]);

        $groupA = $context->createGroup($competition, 'Grupo A');
        $groupB = $context->createGroup($competition, 'Grupo B');

        $context->assignEntryToGroupViaApi($groupA, $entries[0])->assertCreated();
        $context->assignEntryToGroupViaApi($groupA, $entries[1])->assertCreated();
        $context->assignEntryToGroupViaApi($groupB, $entries[2])->assertCreated();
        $context->assignEntryToGroupViaApi($groupB, $entries[3])->assertCreated();

        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();

        $gamesA = Game::query()->where('group_id', $groupA->id)->get();
        $gamesB = Game::query()->where('group_id', $groupB->id)->get();

        $context->finishGameByEntryViaApi(
            $context->findGameBetweenEntries($gamesA, $entries[0]->id, $entries[1]->id),
            (int) $entries[0]->id,
        )->assertOk();

        $context->finishGameByEntryViaApi(
            $context->findGameBetweenEntries($gamesB, $entries[2]->id, $entries[3]->id),
            (int) $entries[2]->id,
        )->assertOk();

        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $this->assertCount(2, $semifinals);

        foreach ($semifinals as $game) {
            $response = $this->getJson($context->apiUrl("games/{$game->id}"))
                ->assertOk()
                ->assertJsonPath('data.player1', null);

            $this->assertNotEmpty($response->json('data.side1.display_name'));
        }
    }
}
