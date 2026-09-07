<?php

namespace Tests\Feature\PlayingTable;

use App\Actions\PlayingTable\CreatePlayingTableAction;
use App\Actions\PlayingTable\DeletePlayingTableAction;
use App\Enums\TournamentStatus;
use App\Models\PlayingTable;
use App\Support\Tournament\TournamentLifecycleGuard;
use Tests\TestCase;

class PlayingTableAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
    }

    protected function tearDown(): void
    {
        $this->resetKeycloakClock();

        parent::tearDown();
    }

    public function test_list_is_public_and_returns_tables_for_the_tournament(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();
        $otherTournament = $context->createTournament(['name' => 'Otro torneo']);

        $first = PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 2,
            'sort_order' => 2,
        ]);
        $second = PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);
        PlayingTable::factory()->create([
            'tournament_id' => $otherTournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        $this->getJson($context->apiUrl("tournaments/{$tournament->id}/playing-tables"))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonMissingPath('data.0.games')
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'number',
                        'name',
                        'display_name',
                        'active',
                        'sort_order',
                    ],
                ],
            ]);
    }

    public function test_list_orders_by_sort_order_then_number(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();

        PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 3,
            'sort_order' => 10,
        ]);
        PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'sort_order' => 10,
        ]);
        PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 2,
            'sort_order' => 1,
        ]);

        $this->getJson($context->apiUrl("tournaments/{$tournament->id}/playing-tables"))
            ->assertOk()
            ->assertJsonPath('data.0.number', 2)
            ->assertJsonPath('data.1.number', 1)
            ->assertJsonPath('data.2.number', 3);
    }

    public function test_resource_display_name_uses_name_when_present(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();

        PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'name' => null,
            'sort_order' => 1,
        ]);
        PlayingTable::factory()->named('Mesa Central')->create([
            'tournament_id' => $tournament->id,
            'number' => 2,
            'sort_order' => 2,
        ]);

        $this->getJson($context->apiUrl("tournaments/{$tournament->id}/playing-tables"))
            ->assertOk()
            ->assertJsonPath('data.0.display_name', 'Mesa 1')
            ->assertJsonPath('data.0.name', null)
            ->assertJsonPath('data.1.display_name', 'Mesa Central')
            ->assertJsonPath('data.1.name', 'Mesa Central');
    }

    public function test_guest_cannot_create_playing_table(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();

        $this->postJson($context->apiUrl("tournaments/{$tournament->id}/playing-tables"), [
            'number' => 1,
        ])
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'No autenticado.',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_scorekeeper_cannot_create_playing_table(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();

        $this->postJson($context->apiUrl("tournaments/{$tournament->id}/playing-tables"), [
            'number' => 1,
        ], $this->keycloakAuthHeaders(['scorekeeper']))
            ->assertForbidden()
            ->assertJson([
                'message' => 'No autorizado.',
                'code' => 'forbidden',
            ]);
    }

    public function test_organizer_can_create_playing_table_with_defaults(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();

        $data = $this->postJson($context->apiUrl("tournaments/{$tournament->id}/playing-tables"), [
            'number' => 5,
        ], $this->keycloakAuthHeaders(['organizer']))
            ->assertCreated()
            ->json('data');

        $this->assertSame(5, $data['number']);
        $this->assertNull($data['name']);
        $this->assertSame('Mesa 5', $data['display_name']);
        $this->assertTrue($data['active']);
        $this->assertSame(5, $data['sort_order']);

        $this->assertDatabaseHas('playing_tables', [
            'id' => $data['id'],
            'tournament_id' => $tournament->id,
            'number' => 5,
            'name' => null,
            'active' => true,
            'sort_order' => 5,
        ]);
    }

    public function test_create_accepts_explicit_fields_and_trims_empty_name_to_null(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();

        $data = $this->postJson($context->apiUrl("tournaments/{$tournament->id}/playing-tables"), [
            'number' => 3,
            'name' => '  Mesa Central  ',
            'active' => false,
            'sort_order' => 10,
        ], $this->keycloakAuthHeaders(['organizer']))
            ->assertCreated()
            ->json('data');

        $this->assertSame('Mesa Central', $data['name']);
        $this->assertSame('Mesa Central', $data['display_name']);
        $this->assertFalse($data['active']);
        $this->assertSame(10, $data['sort_order']);

        $this->postJson($context->apiUrl("tournaments/{$tournament->id}/playing-tables"), [
            'number' => 4,
            'name' => '   ',
        ], $this->keycloakAuthHeaders(['organizer']))
            ->assertCreated()
            ->assertJsonPath('data.name', null)
            ->assertJsonPath('data.display_name', 'Mesa 4');
    }

    public function test_create_rejects_duplicate_number_in_the_same_tournament(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();

        PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        $this->postJson($context->apiUrl("tournaments/{$tournament->id}/playing-tables"), [
            'number' => 1,
        ], $this->keycloakAuthHeaders(['organizer']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.number.0', CreatePlayingTableAction::DUPLICATE_NUMBER_MESSAGE);
    }

    public function test_create_allows_the_same_number_in_another_tournament(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();
        $otherTournament = $context->createTournament(['name' => 'Otro torneo']);

        PlayingTable::factory()->create([
            'tournament_id' => $otherTournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        $this->postJson($context->apiUrl("tournaments/{$tournament->id}/playing-tables"), [
            'number' => 1,
        ], $this->keycloakAuthHeaders(['organizer']))
            ->assertCreated()
            ->assertJsonPath('data.number', 1);
    }

    public function test_create_is_blocked_when_tournament_is_finished(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament([
            'status' => TournamentStatus::Finished,
            'closed_at' => now(),
        ]);

        $this->postJson($context->apiUrl("tournaments/{$tournament->id}/playing-tables"), [
            'number' => 1,
        ], $this->keycloakAuthHeaders(['organizer']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.tournament.0', TournamentLifecycleGuard::LOCK_MESSAGE);
    }

    public function test_organizer_can_update_playing_table_via_patch_and_put(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();
        $table = PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'name' => null,
            'active' => true,
            'sort_order' => 1,
        ]);

        $this->patchJson(
            $context->apiUrl("tournaments/{$tournament->id}/playing-tables/{$table->id}"),
            [
                'number' => 8,
                'name' => 'Cancha Principal',
                'sort_order' => 3,
                'active' => false,
            ],
            $this->keycloakAuthHeaders(['organizer']),
        )
            ->assertOk()
            ->assertJsonPath('data.number', 8)
            ->assertJsonPath('data.name', 'Cancha Principal')
            ->assertJsonPath('data.display_name', 'Cancha Principal')
            ->assertJsonPath('data.sort_order', 3)
            ->assertJsonPath('data.active', false);

        $this->putJson(
            $context->apiUrl("tournaments/{$tournament->id}/playing-tables/{$table->id}"),
            [
                'name' => 'Mesa Central',
                'active' => true,
            ],
            $this->keycloakAuthHeaders(['organizer']),
        )
            ->assertOk()
            ->assertJsonPath('data.number', 8)
            ->assertJsonPath('data.name', 'Mesa Central')
            ->assertJsonPath('data.active', true);
    }

    public function test_update_unique_number_ignores_self_and_trims_empty_name(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();
        $table = PlayingTable::factory()->named('Original')->create([
            'tournament_id' => $tournament->id,
            'number' => 2,
            'sort_order' => 2,
        ]);

        $this->patchJson(
            $context->apiUrl("tournaments/{$tournament->id}/playing-tables/{$table->id}"),
            ['number' => 2],
            $this->keycloakAuthHeaders(['organizer']),
        )
            ->assertOk()
            ->assertJsonPath('data.number', 2);

        $this->patchJson(
            $context->apiUrl("tournaments/{$tournament->id}/playing-tables/{$table->id}"),
            ['name' => '   '],
            $this->keycloakAuthHeaders(['organizer']),
        )
            ->assertOk()
            ->assertJsonPath('data.name', null)
            ->assertJsonPath('data.display_name', 'Mesa 2');
    }

    public function test_update_rejects_duplicate_number_of_another_table(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();
        PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);
        $table = PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 2,
            'sort_order' => 2,
        ]);

        $this->patchJson(
            $context->apiUrl("tournaments/{$tournament->id}/playing-tables/{$table->id}"),
            ['number' => 1],
            $this->keycloakAuthHeaders(['organizer']),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.number.0', CreatePlayingTableAction::DUPLICATE_NUMBER_MESSAGE);
    }

    public function test_cannot_update_playing_table_from_another_tournament(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();
        $otherTournament = $context->createTournament(['name' => 'Otro torneo']);
        $foreignTable = PlayingTable::factory()->create([
            'tournament_id' => $otherTournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        $this->patchJson(
            $context->apiUrl("tournaments/{$tournament->id}/playing-tables/{$foreignTable->id}"),
            ['name' => 'Intrusa'],
            $this->keycloakAuthHeaders(['organizer']),
        )->assertNotFound();
    }

    public function test_scorekeeper_cannot_update_playing_table(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();
        $table = PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        $this->patchJson(
            $context->apiUrl("tournaments/{$tournament->id}/playing-tables/{$table->id}"),
            ['active' => false],
            $this->keycloakAuthHeaders(['scorekeeper']),
        )->assertForbidden();
    }

    public function test_update_is_blocked_when_tournament_is_finished(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();
        $table = PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);
        $tournament->update([
            'status' => TournamentStatus::Finished,
            'closed_at' => now(),
        ]);

        $this->patchJson(
            $context->apiUrl("tournaments/{$tournament->id}/playing-tables/{$table->id}"),
            ['name' => 'Mesa Central'],
            $this->keycloakAuthHeaders(['organizer']),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.tournament.0', TournamentLifecycleGuard::LOCK_MESSAGE);
    }

    public function test_organizer_can_delete_unreferenced_playing_table(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();
        $table = PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        $this->deleteJson(
            $context->apiUrl("tournaments/{$tournament->id}/playing-tables/{$table->id}"),
            [],
            $this->keycloakAuthHeaders(['organizer']),
        )->assertNoContent();

        $this->assertDatabaseMissing('playing_tables', ['id' => $table->id]);
    }

    public function test_delete_rejects_playing_table_referenced_by_a_game(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();
        $tournament = $setup['competition']->tournament;
        $table = PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        $setup['game']->update(['playing_table_id' => $table->id]);

        $this->deleteJson(
            $context->apiUrl("tournaments/{$tournament->id}/playing-tables/{$table->id}"),
            [],
            $this->keycloakAuthHeaders(['organizer']),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.playing_table.0', DeletePlayingTableAction::REFERENCED_MESSAGE);

        $this->assertDatabaseHas('playing_tables', ['id' => $table->id]);
        $this->assertDatabaseHas('games', [
            'id' => $setup['game']->id,
            'playing_table_id' => $table->id,
        ]);
    }

    public function test_cannot_delete_playing_table_from_another_tournament(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();
        $otherTournament = $context->createTournament(['name' => 'Otro torneo']);
        $foreignTable = PlayingTable::factory()->create([
            'tournament_id' => $otherTournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        $this->deleteJson(
            $context->apiUrl("tournaments/{$tournament->id}/playing-tables/{$foreignTable->id}"),
            [],
            $this->keycloakAuthHeaders(['organizer']),
        )->assertNotFound();

        $this->assertDatabaseHas('playing_tables', ['id' => $foreignTable->id]);
    }

    public function test_guest_cannot_delete_playing_table(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();
        $table = PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        $this->deleteJson(
            $context->apiUrl("tournaments/{$tournament->id}/playing-tables/{$table->id}"),
        )->assertUnauthorized();
    }

    public function test_scorekeeper_cannot_delete_playing_table(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();
        $table = PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        $this->deleteJson(
            $context->apiUrl("tournaments/{$tournament->id}/playing-tables/{$table->id}"),
            [],
            $this->keycloakAuthHeaders(['scorekeeper']),
        )->assertForbidden();
    }

    public function test_delete_is_blocked_when_tournament_is_finished(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();
        $table = PlayingTable::factory()->create([
            'tournament_id' => $tournament->id,
            'number' => 1,
            'sort_order' => 1,
        ]);
        $tournament->update([
            'status' => TournamentStatus::Finished,
            'closed_at' => now(),
        ]);

        $this->deleteJson(
            $context->apiUrl("tournaments/{$tournament->id}/playing-tables/{$table->id}"),
            [],
            $this->keycloakAuthHeaders(['organizer']),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.tournament.0', TournamentLifecycleGuard::LOCK_MESSAGE);

        $this->assertDatabaseHas('playing_tables', ['id' => $table->id]);
    }
}
