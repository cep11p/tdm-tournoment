<?php

namespace Tests\Feature\Competition;

use App\Models\Bracket;
use App\Models\Competition;
use App\Models\Game;
use App\Support\Competition\CompetitionEntryDisplayName;
use Tests\TestCase;

class CompetitionStandingsDoublesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_global_standings_doubles_do_not_crash_and_expose_entry_fields(): void
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

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.competition_entry_id', fn ($value): bool => $value !== null)
            ->assertJsonPath('data.0.display_name', fn ($value): bool => is_string($value) && $value !== '')
            ->assertJsonCount(2, 'data.0.members')
            ->assertJsonPath('data.0.player_id', null)
            ->assertJsonPath('data.0.player_name', null);

        $firstEntry = $entries[0]->fresh(['members.player']);
        $firstStanding = collect($response->json('data'))
            ->firstWhere('competition_entry_id', $firstEntry->id);

        $this->assertNotNull($firstStanding);
        $this->assertSame(1, $firstStanding['won']);
        $this->assertSame(0, $firstStanding['lost']);
        $this->assertSame(
            CompetitionEntryDisplayName::for($firstEntry),
            $firstStanding['display_name'],
        );
    }

    public function test_global_standings_doubles_track_wins_and_losses(): void
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

        $roundOne = $context->bracketGamesForRound($bracket, 1);

        foreach ($roundOne as $game) {
            if ($game->is_bye) {
                continue;
            }

            $context->finishGameByEntryViaApi($game, (int) $game->entry1_id)->assertOk();
        }

        $winnerEntryId = (int) $roundOne->first(fn (Game $game) => ! $game->is_bye)->entry1_id;
        $loserEntryId = (int) $roundOne->first(fn (Game $game) => ! $game->is_bye)->entry2_id;

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/standings"));
        $standings = collect($response->json('data'))->keyBy('competition_entry_id');

        $this->assertSame(1, $standings[$winnerEntryId]['won']);
        $this->assertSame(0, $standings[$winnerEntryId]['lost']);
        $this->assertSame(0, $standings[$loserEntryId]['won']);
        $this->assertSame(1, $standings[$loserEntryId]['lost']);
    }

    public function test_global_standings_singles_keep_player_fields(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('data.0.player_id', fn ($value): bool => $value !== null)
            ->assertJsonPath('data.0.player_name', fn ($value): bool => is_string($value) && $value !== '');
    }
}
