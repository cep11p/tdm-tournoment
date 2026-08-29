<?php

namespace Tests\Feature\Game;

use App\Enums\AuditAction;
use App\Enums\GameStatus;
use App\Enums\ThirdPlaceMode;
use App\Models\Bracket;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Support\Competition\CompetitionEntryDisplayName;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class GameDoublesResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_singles_resource_keeps_legacy_player_fields_and_exposes_sides(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();

        $response = $this->getJson($context->apiUrl("games/{$setup['game']->id}"))
            ->assertOk()
            ->assertJsonPath('data.player1.id', $setup['playerOne']->id)
            ->assertJsonPath('data.player2.id', $setup['playerTwo']->id)
            ->assertJsonPath('data.winner_id', null)
            ->assertJsonPath('data.side1.competition_entry_id', $setup['game']->entry1_id)
            ->assertJsonPath('data.side2.competition_entry_id', $setup['game']->entry2_id);

        $this->assertNotNull($response->json('data.side1.display_name'));
        $this->assertNotNull($response->json('data.side2.display_name'));
    }

    public function test_doubles_resource_exposes_sides_and_null_legacy_player_fields(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingDoublesGame();
        [$entryOne, $entryTwo] = $setup['entries'];

        $response = $this->getJson($context->apiUrl("games/{$setup['game']->id}"))
            ->assertOk()
            ->assertJsonPath('data.side1.competition_entry_id', $entryOne->id)
            ->assertJsonPath('data.side2.competition_entry_id', $entryTwo->id)
            ->assertJsonPath('data.player1', null)
            ->assertJsonPath('data.player2', null)
            ->assertJsonPath('data.winner_id', null)
            ->assertJsonPath('data.winner_entry_id', null);

        $this->assertSame(
            CompetitionEntryDisplayName::for($entryOne->fresh('members.player')),
            $response->json('data.side1.display_name'),
        );
        $this->assertCount(2, $response->json('data.side1.members'));
        $this->assertCount(2, $response->json('data.side2.members'));
    }

    public function test_list_games_for_doubles_competition_does_not_error(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingDoublesGame();

        $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/games"))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.side1.competition_entry_id', $setup['entries'][0]->id);
    }

    public function test_record_set_assigns_winner_entry_for_doubles(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingDoublesGame(setsToWin: 1);
        $winnerEntryId = (int) $setup['entries'][1]->id;

        $context->finishGameByEntryViaApi($setup['game'], $winnerEntryId)
            ->assertOk()
            ->assertJsonPath('data.winner_entry_id', $winnerEntryId)
            ->assertJsonPath('data.player1', null)
            ->assertJsonPath('data.player2', null)
            ->assertJsonPath('data.winner_id', null);

        $game = $setup['game']->fresh();
        $this->assertSame($winnerEntryId, (int) $game->winner_entry_id);
        $this->assertSame(GameStatus::Finished, $game->status);
    }

    public function test_record_set_audit_for_doubles_does_not_crash(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingDoublesGame(setsToWin: 1);
        $winnerEntryId = (int) $setup['entries'][0]->id;

        $context->finishGameByEntryViaApi($setup['game'], $winnerEntryId)->assertOk();

        $activity = Activity::query()
            ->where('description', AuditAction::GAME_SET_RECORDED->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($winnerEntryId, data_get($activity->properties, 'summary.winner_entry_id'));
        $this->assertNotNull(data_get($activity->properties, 'summary.winner_display_name'));
        $this->assertNull(data_get($activity->properties, 'summary.winner_id'));
    }

    public function test_manual_doubles_game_created_by_entry_ids(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingDoublesGame();

        $this->assertSame($setup['entries'][0]->id, $setup['game']->entry1_id);
        $this->assertSame($setup['entries'][1]->id, $setup['game']->entry2_id);
    }

    public function test_manual_doubles_game_rejects_player_ids(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(4);
        $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
        ]);

        $this->postJson($context->apiUrl("competitions/{$competition->id}/games"), [
            'player1_id' => $players[0]->id,
            'player2_id' => $players[2]->id,
        ], $this->authHeaders(['organizer']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entry1_id']);
    }

    public function test_correction_changes_winner_entry_for_doubles(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingDoublesGame(setsToWin: 1);
        $entryOneId = (int) $setup['entries'][0]->id;
        $entryTwoId = (int) $setup['entries'][1]->id;

        $context->finishGameByEntryViaApi($setup['game'], $entryOneId)->assertOk();

        $context->correctResult($setup['game']->fresh(), 'ajuste de prueba doubles', [
            ['player1_score' => 0, 'player2_score' => 11],
        ])->assertOk()
            ->assertJsonPath('data.winner_entry_id', $entryTwoId)
            ->assertJsonPath('data.winner_id', null);

        $this->assertSame($entryTwoId, (int) $setup['game']->fresh()->winner_entry_id);
    }

    public function test_bye_doubles_game_resource_shape(): void
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

        $byeGame = Game::query()
            ->where('competition_id', $competition->id)
            ->where('is_bye', true)
            ->firstOrFail();

        $this->getJson($context->apiUrl("games/{$byeGame->id}"))
            ->assertOk()
            ->assertJsonPath('data.is_bye', true)
            ->assertJsonPath('data.side2', null)
            ->assertJsonPath('data.winner_entry_id', $byeGame->entry1_id)
            ->assertJsonPath('data.player1', null)
            ->assertJsonPath('data.player2', null);
    }
}
