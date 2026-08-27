<?php

namespace Tests\Feature\Game;

use App\Actions\Game\CreateGameAction;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Support\Competition\ResolveSinglesEntryForPlayer;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GameEntryIdentityTest extends TestCase
{
    public function test_round_robin_persists_entry_ids_not_player_ids(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()->where('group_id', $group->id)->get();
        $resolveEntry = app(ResolveSinglesEntryForPlayer::class);

        $this->assertGreaterThan(0, $games->count());
        $this->assertTrue($games->every(fn (Game $game): bool => $game->entry1_id !== null && $game->entry2_id !== null));
        $this->assertFalse(
            Game::query()->where('group_id', $group->id)->whereColumn('entry1_id', 'entry2_id')->exists(),
        );

        foreach ($games as $game) {
            $this->assertSame(
                $resolveEntry($competition, (int) $game->singlesPlayer1Id())->id,
                (int) $game->entry1_id,
            );
            $this->assertSame(
                $resolveEntry($competition, (int) $game->singlesPlayer2Id())->id,
                (int) $game->entry2_id,
            );
            $this->assertSame((int) $competition->id, (int) $game->competition_id);
        }
    }

    public function test_game_cannot_mix_entries_from_another_competition(): void
    {
        $context = $this->tournamentContext();
        $competitionA = $context->createCompetition();
        $competitionB = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competitionA, $players);
        $context->registerPlayers($competitionB, $players);

        $entryA = $context->entryIdFor($competitionA, $players[0]);
        $entryB = $context->entryIdFor($competitionB, $players[1]);

        $this->expectException(ValidationException::class);

        app(CreateGameAction::class)([
            'competition_id' => $competitionA->id,
            'entry1_id' => $entryA,
            'entry2_id' => $entryB,
        ]);
    }

    public function test_same_player_in_two_competitions_uses_distinct_entries(): void
    {
        $context = $this->tournamentContext();
        $competitionA = $context->createCompetition();
        $competitionB = $context->createCompetition();
        $shared = $context->createPlayers(1)[0];
        $otherA = $context->createPlayers(1)[0];
        $otherB = $context->createPlayers(1)[0];

        $context->registerPlayers($competitionA, [$shared, $otherA]);
        $context->registerPlayers($competitionB, [$shared, $otherB]);

        $gameA = $context->persistGame($competitionA, $shared, $otherA);
        $gameB = $context->persistGame($competitionB, $shared, $otherB);

        $this->assertNotSame($gameA->entry1_id, $gameB->entry1_id);
        $this->assertSame($shared->id, $gameA->singlesPlayer1Id());
        $this->assertSame($shared->id, $gameB->singlesPlayer1Id());
    }

    public function test_bye_sets_entry2_null_and_winner_to_entry1(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $player = $context->createPlayers(1)[0];
        $context->registerPlayer($competition, $player);

        $game = $context->persistByeGame($competition, $player);

        $this->assertTrue($game->is_bye);
        $this->assertNull($game->entry2_id);
        $this->assertSame($game->entry1_id, $game->winner_entry_id);
        $this->assertSame(GameStatus::Finished, $game->status);
        $this->assertSame($player->id, $game->singlesWinnerId());
        $this->assertNull($game->singlesPlayer2Id());
    }

    public function test_winner_must_belong_to_a_side(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);

        $this->expectException(ValidationException::class);

        $context->persistGame($competition, $players[0], $players[1], [
            'winner_entry_id' => $context->entryIdFor($competition, $players[2]),
        ]);
    }

    public function test_api_singles_resource_still_exposes_player_ids(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $response = $this->getJson($context->apiUrl("games/{$setup['game']->id}"), $this->authHeaders(['organizer']))
            ->assertOk()
            ->assertJsonPath('data.player1.id', $setup['playerOne']->id)
            ->assertJsonPath('data.player2.id', $setup['playerTwo']->id)
            ->assertJsonPath('data.winner_id', null);

        $this->assertArrayNotHasKey('entry1_id', $response->json('data'));
    }

    public function test_record_set_assigns_winner_entry_of_the_winning_side(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 1);

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 0)
            ->assertOk();

        $game = $setup['game']->fresh();

        $this->assertSame((int) $game->entry1_id, (int) $game->winner_entry_id);
        $this->assertSame($setup['playerOne']->id, $game->singlesWinnerId());
        $this->assertSame(11, (int) $game->sets()->first()->player1_score);
        $this->assertSame(0, (int) $game->sets()->first()->player2_score);
    }

    public function test_correction_changes_winner_entry_and_keeps_set_scores_side_based(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame(setsToWin: 1);

        $context->recordSet($setup['game'], setNumber: 1, player1Score: 11, player2Score: 0)
            ->assertOk();

        $context->correctResult($setup['game'], 'ajuste de prueba', [
            ['player1_score' => 0, 'player2_score' => 11],
        ])->assertOk();

        $game = $setup['game']->fresh(['sets']);

        $this->assertSame((int) $game->entry2_id, (int) $game->winner_entry_id);
        $this->assertSame($setup['playerTwo']->id, $game->singlesWinnerId());
        $this->assertSame(0, (int) $game->sets->first()->player1_score);
        $this->assertSame(11, (int) $game->sets->first()->player2_score);
    }

    public function test_next_round_copies_winner_entry_ids(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = $competition->fresh()->brackets()->firstOrFail();
        $roundOne = $context->bracketGamesForRound($bracket, 1);

        foreach ($roundOne as $game) {
            $game->load(Game::DISPLAY_RELATIONS);
            $context->finishGame($game, $game->singlesPlayer1())->assertOk();
        }

        $context->generateBracketNextRound($bracket)->assertCreated();

        $winners = $roundOne
            ->sortBy('bracket_match')
            ->map(fn (Game $game): int => (int) $game->fresh()->winner_entry_id)
            ->values();
        $nextRound = $context->bracketGamesForRound($bracket->fresh(), 2)->first();

        $this->assertNotNull($nextRound);
        $this->assertSame($winners[0], (int) $nextRound->entry1_id);
        $this->assertSame($winners[1], (int) $nextRound->entry2_id);
    }

    public function test_same_entry_cannot_occupy_both_sides(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $entryId = $context->entryIdFor($competition, $players[0]);

        $this->expectException(ValidationException::class);

        app(CreateGameAction::class)([
            'competition_id' => $competition->id,
            'entry1_id' => $entryId,
            'entry2_id' => $entryId,
        ]);
    }
}
