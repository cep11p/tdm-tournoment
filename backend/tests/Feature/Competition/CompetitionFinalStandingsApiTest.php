<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionFinalStandingSource;
use App\Enums\ThirdPlaceMode;
use App\Http\Controllers\Api\V1\CompetitionFinalStandingsController;
use App\Models\Player;
use App\Support\Competition\CompetitionEntryDisplayName;
use Tests\TestCase;

class CompetitionFinalStandingsApiTest extends TestCase
{
    public function test_completed_returns_ordered_entry_centric_payload(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Shared]);
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $context->completeCompetitionThroughFinal($competition);

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/final-standings"));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(4, $data);

        $positions = array_column($data, 'position');
        $this->assertSame($positions, collect($positions)->sort()->values()->all());

        $sharedThird = array_values(array_filter($data, fn (array $row): bool => $row['position'] === 3));
        $this->assertCount(2, $sharedThird);
        $this->assertSame(4, $sharedThird[0]['position_range_end']);
        $this->assertSame(4, $sharedThird[1]['position_range_end']);
        $this->assertSame(CompetitionFinalStandingSource::Semifinal->value, $sharedThird[0]['source']);

        foreach ($data as $row) {
            $this->assertArrayHasKey('competition_entry_id', $row);
            $this->assertArrayHasKey('display_name', $row);
            $this->assertArrayHasKey('position', $row);
            $this->assertArrayHasKey('position_range_end', $row);
            $this->assertArrayHasKey('source', $row);
            $this->assertArrayNotHasKey('ranking_points', $row);
            $this->assertArrayNotHasKey('player_id', $row);
            $this->assertArrayNotHasKey('id', $row);
        }

        $first = $data[0];
        $this->assertSame(1, $first['position']);
        $this->assertSame(CompetitionFinalStandingSource::Final->value, $first['source']);
    }

    public function test_incomplete_returns_422(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);

        $this->getJson($context->apiUrl("competitions/{$competition->id}/final-standings"))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath('errors.competition.0', CompetitionFinalStandingsController::UNAVAILABLE_MESSAGE);
    }

    public function test_display_name_snapshot_does_not_follow_live_player_rename(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $context->completeCompetitionThroughFinal($competition);

        $championStanding = $competition->finalStandings()->where('position', 1)->firstOrFail();
        $originalName = $championStanding->display_name_snapshot;
        $this->assertNotSame('', $originalName);

        $championEntry = $championStanding->entry()->firstOrFail();
        $player = $championEntry->singlesPlayer();
        $this->assertInstanceOf(Player::class, $player);
        $player->update(['first_name' => 'Renombrado']);

        $this->assertNotSame(
            $originalName,
            CompetitionEntryDisplayName::for($championEntry->fresh(['members.player'])),
        );

        $this->getJson($context->apiUrl("competitions/{$competition->id}/final-standings"))
            ->assertOk()
            ->assertJsonPath('data.0.display_name', $originalName);
    }
}
