<?php

namespace Tests\Feature\Registration;

use App\Actions\Registration\RegisterPlayerToCompetitionAction;
use App\Enums\CompetitionEntryStatus;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompetitionEntryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_singles_registration_creates_competition_entry(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competition, $player)->assertCreated();

        $this->assertDatabaseCount('competition_entries', 1);
    }

    public function test_creates_exactly_one_entry_per_player_and_competition(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competition, $player)->assertCreated();

        $this->assertSame(1, CompetitionEntryMember::query()
            ->where('competition_id', $competition->id)
            ->where('player_id', $player->id)
            ->count());
    }

    public function test_entry_belongs_to_correct_competition(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competition, $player)->assertCreated();

        $entry = CompetitionEntry::query()->sole();

        $this->assertSame($competition->id, $entry->competition_id);
    }

    public function test_entry_status_is_active(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competition, $player)->assertCreated();

        $entry = CompetitionEntry::query()->sole();

        $this->assertSame(CompetitionEntryStatus::Active, $entry->status);
    }

    public function test_entry_has_exactly_one_member(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competition, $player)->assertCreated();

        $entry = CompetitionEntry::query()->with('members')->sole();

        $this->assertCount(1, $entry->members);
    }

    public function test_member_corresponds_to_player(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competition, $player)->assertCreated();

        $member = CompetitionEntryMember::query()->sole();

        $this->assertSame($player->id, $member->player_id);
    }

    public function test_member_order_is_one_for_singles(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competition, $player)->assertCreated();

        $member = CompetitionEntryMember::query()->sole();

        $this->assertSame(1, $member->member_order);
    }

    public function test_same_player_cannot_be_in_two_entries_of_same_competition(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competition, $player)->assertCreated();

        $context->registerPlayerViaApi($competition, $player)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);

        $this->assertDatabaseCount('competition_entries', 1);
        $this->assertDatabaseCount('competition_entry_members', 1);
    }

    public function test_same_player_can_be_in_entries_of_different_competitions(): void
    {
        $context = $this->tournamentContext();
        $competitionA = $context->createCompetition();
        $competitionB = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competitionA, $player)->assertCreated();
        $context->registerPlayerViaApi($competitionB, $player)->assertCreated();

        $this->assertDatabaseCount('competition_entries', 2);
        $this->assertSame(2, CompetitionEntryMember::query()
            ->where('player_id', $player->id)
            ->count());
    }

    public function test_bulk_creates_one_entry_per_new_participation(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);

        $response = $this->postJson($context->apiUrl("competitions/{$competition->id}/registrations/bulk"), [
            'player_ids' => array_map(fn ($player) => $player->id, $players),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('created', 3);

        $this->assertDatabaseCount('competition_entries', 3);
        $this->assertDatabaseCount('competition_entry_members', 3);
    }

    public function test_repeated_bulk_does_not_duplicate_entries(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $playerIds = array_map(fn ($player) => $player->id, $players);

        $this->postJson($context->apiUrl("competitions/{$competition->id}/registrations/bulk"), [
            'player_ids' => $playerIds,
        ])->assertOk();

        $response = $this->postJson($context->apiUrl("competitions/{$competition->id}/registrations/bulk"), [
            'player_ids' => $playerIds,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('skipped', 3);

        $this->assertDatabaseCount('competition_entries', 3);
        $this->assertDatabaseCount('competition_entry_members', 3);
    }

    public function test_failed_duplicate_registration_does_not_leave_partial_data(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competition, $player)->assertCreated();

        $context->registerPlayerViaApi($competition, $player)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);

        $this->assertDatabaseCount('competition_entries', 1);
        $this->assertDatabaseCount('competition_entry_members', 1);
        $this->assertDatabaseCount('competition_entries', 1);
    }

    public function test_deleting_competition_cascades_entries_and_members(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);

        $competition->delete();

        $this->assertDatabaseCount('competition_entries', 0);
        $this->assertDatabaseCount('competition_entry_members', 0);
    }

    public function test_tournament_test_context_register_player_creates_entry_and_member(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayer($competition, $player);

        $this->assertDatabaseCount('competition_entries', 1);
        $this->assertDatabaseCount('competition_entry_members', 1);
        $this->assertDatabaseCount('competition_entries', 1);

        $member = CompetitionEntryMember::query()->sole();

        $this->assertSame($player->id, $member->player_id);
        $this->assertSame($competition->id, $member->competition_id);
        $this->assertSame(1, $member->member_order);
    }

    public function test_transaction_rollback_on_mid_persistence_failure(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $threw = false;

        try {
            DB::transaction(function () use ($competition, $player): void {
                app(RegisterPlayerToCompetitionAction::class)([
                    'competition_id' => $competition->id,
                    'player_id' => $player->id,
                ]);

                throw new \RuntimeException('Simulated failure after registration');
            });
        } catch (\RuntimeException) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertDatabaseCount('competition_entries', 0);
        $this->assertDatabaseCount('competition_entry_members', 0);
        $this->assertDatabaseCount('competition_entries', 0);
    }
}
