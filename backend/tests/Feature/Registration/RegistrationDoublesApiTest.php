<?php

namespace Tests\Feature\Registration;

use App\Enums\AuditAction;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RegistrationDoublesApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_post_doubles_creates_entry_with_two_members(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2] = $context->createPlayers(2);

        $context->registerPairViaApi($competition, $player1, $player2)->assertCreated();

        $this->assertDatabaseCount('competition_entries', 1);
        $this->assertDatabaseCount('competition_entry_members', 2);
    }

    public function test_post_doubles_response_has_correct_display_name(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2] = $context->createPlayers(2);
        $player1->update(['first_name' => 'Carlos', 'last_name' => 'Pérez']);
        $player2->update(['first_name' => 'Juan', 'last_name' => 'Gómez']);

        $response = $context->registerPairViaApi(
            $competition,
            $player1->fresh(),
            $player2->fresh(),
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Carlos Pérez / Juan Gómez');
    }

    public function test_post_doubles_response_members_are_ordered_by_member_order(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2] = $context->createPlayers(2);

        $response = $context->registerPairViaApi($competition, $player2, $player1);

        $response
            ->assertCreated()
            ->assertJsonPath('data.members.0.id', $player2->id)
            ->assertJsonPath('data.members.1.id', $player1->id);
    }

    public function test_post_doubles_response_player_is_explicitly_null(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2] = $context->createPlayers(2);

        $context->registerPairViaApi($competition, $player1, $player2)
            ->assertCreated()
            ->assertJsonPath('data.player', null);
    }

    public function test_get_registrations_returns_one_row_per_pair(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2, $player3, $player4] = $context->createPlayers(4);

        $context->registerPairViaApi($competition, $player1, $player2)->assertCreated();
        $context->registerPairViaApi($competition, $player3, $player4)->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/registrations"));

        $response->assertOk();

        $data = $response->json('data');

        $this->assertCount(2, $data);
        $this->assertCount(2, $data[0]['members']);
        $this->assertCount(2, $data[1]['members']);
        $this->assertNull($data[0]['player']);
        $this->assertNull($data[1]['player']);
    }

    public function test_same_player_twice_rejects_with_422(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player] = $context->createPlayers(1);

        $response = $this->postJson($context->apiUrl("competitions/{$competition->id}/registrations"), [
            'player_ids' => [$player->id, $player->id],
        ]);

        $response->assertUnprocessable();

        $errors = array_keys($response->json('errors'));
        $this->assertTrue(
            in_array('player_ids', $errors, true) || in_array('player_ids.0', $errors, true),
        );

        $this->assertDatabaseCount('competition_entries', 0);
    }

    public function test_player_already_used_rejects_with_422(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2, $player3] = $context->createPlayers(3);

        $context->registerPairViaApi($competition, $player1, $player2)->assertCreated();

        $response = $context->registerPairViaApi($competition, $player1, $player3);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids']);

        $this->assertDatabaseCount('competition_entries', 1);
    }

    public function test_player_id_payload_in_doubles_rejects_with_422(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player] = $context->createPlayers(1);

        $response = $this->postJson($context->apiUrl("competitions/{$competition->id}/registrations"), [
            'player_id' => $player->id,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);
    }

    public function test_player_ids_payload_in_singles_rejects_with_422(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player1, $player2] = $context->createPlayers(2);

        $response = $this->postJson($context->apiUrl("competitions/{$competition->id}/registrations"), [
            'player_ids' => [$player1->id, $player2->id],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids']);
    }

    public function test_bulk_doubles_rejects_with_422(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(2);
        $playerIds = array_map(static fn ($player) => $player->id, $players);

        $response = $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/registrations/bulk"),
            ['player_ids' => $playerIds],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids'])
            ->assertJsonPath(
                'errors.player_ids.0',
                'El registro masivo de parejas todavía no está disponible.',
            );

        $this->assertDatabaseCount('competition_entries', 0);
    }

    public function test_doubles_audit_includes_both_members_and_display_name(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2] = $context->createPlayers(2);
        $player1->update(['first_name' => 'Carlos', 'last_name' => 'Pérez']);
        $player2->update(['first_name' => 'Juan', 'last_name' => 'Gómez']);

        $context->registerPairViaApi($competition, $player1->fresh(), $player2->fresh())->assertCreated();

        $activity = Activity::query()
            ->where('description', AuditAction::REGISTRATION_CREATED->value)
            ->sole();

        $this->assertSame('registrations', $activity->log_name);
        $this->assertSame(
            [$player1->id, $player2->id],
            data_get($activity->properties, 'summary.member_ids'),
        );
        $this->assertSame(
            ['Carlos Pérez', 'Juan Gómez'],
            data_get($activity->properties, 'summary.member_names'),
        );
        $this->assertSame(
            'Carlos Pérez / Juan Gómez',
            data_get($activity->properties, 'summary.display_name'),
        );
        $this->assertNotNull(data_get($activity->properties, 'summary.competition_entry_id'));
    }

    public function test_singles_api_response_remains_unchanged(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $player->update(['first_name' => 'Ana', 'last_name' => 'López', 'nickname' => 'ana']);

        $response = $context->registerPlayerViaApi($competition, $player->fresh());

        $response
            ->assertCreated()
            ->assertJsonPath('data.player.id', $player->id)
            ->assertJsonPath('data.player.first_name', 'Ana')
            ->assertJsonPath('data.player.last_name', 'López')
            ->assertJsonPath('data.player.nickname', 'ana')
            ->assertJsonPath('data.display_name', 'Ana López')
            ->assertJsonCount(1, 'data.members')
            ->assertJsonPath('data.members.0.id', $player->id);

        $entry = CompetitionEntry::query()->sole();
        $member = CompetitionEntryMember::query()->sole();

        $this->assertSame($entry->id, $member->competition_entry_id);
        $this->assertSame($player->id, $member->player_id);
    }
}
