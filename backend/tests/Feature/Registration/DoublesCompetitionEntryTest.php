<?php

namespace Tests\Feature\Registration;

use App\Actions\CompetitionEntry\PersistCompetitionEntryAction;
use App\Enums\CompetitionType;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Player;
use App\Support\Competition\CompetitionEntryDisplayName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DoublesCompetitionEntryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_competition_type_doubles_is_valid(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();

        $this->assertSame(CompetitionType::Doubles, $competition->type);
        $this->assertSame('doubles', $competition->type->value);
    }

    public function test_valid_pair_creates_one_entry(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2] = $context->createPlayers(2);

        $context->registerPair($competition, $player1, $player2);

        $this->assertDatabaseCount('competition_entries', 1);
    }

    public function test_valid_pair_creates_exactly_two_members(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2] = $context->createPlayers(2);

        $entry = $context->registerPair($competition, $player1, $player2);

        $this->assertCount(2, $entry->members()->get());
        $this->assertDatabaseCount('competition_entry_members', 2);
    }

    public function test_members_have_member_order_one_and_two(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2] = $context->createPlayers(2);

        $context->registerPair($competition, $player1, $player2);

        $members = CompetitionEntryMember::query()
            ->orderBy('member_order')
            ->get();

        $this->assertSame([1, 2], $members->pluck('member_order')->all());
    }

    public function test_preserves_received_player_order(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2] = $context->createPlayers(2);

        $context->registerPair($competition, $player2, $player1);

        $members = CompetitionEntryMember::query()
            ->orderBy('member_order')
            ->get();

        $this->assertSame($player2->id, $members[0]->player_id);
        $this->assertSame($player1->id, $members[1]->player_id);
    }

    public function test_same_player_twice_rejects_with_validation_exception(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player] = $context->createPlayers(1);

        $this->expectException(ValidationException::class);

        app(PersistCompetitionEntryAction::class)([
            'competition_id' => $competition->id,
            'player_ids' => [$player->id, $player->id],
        ]);
    }

    public function test_doubles_with_one_player_rejects(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player] = $context->createPlayers(1);

        $this->expectException(ValidationException::class);

        try {
            app(PersistCompetitionEntryAction::class)([
                'competition_id' => $competition->id,
                'player_ids' => [$player->id],
            ]);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('player_ids', $exception->errors());

            throw $exception;
        }
    }

    public function test_doubles_with_three_players_rejects(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(3);

        $this->expectException(ValidationException::class);

        try {
            app(PersistCompetitionEntryAction::class)([
                'competition_id' => $competition->id,
                'player_ids' => array_map(fn (Player $p) => $p->id, $players),
            ]);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('player_ids', $exception->errors());

            throw $exception;
        }
    }

    public function test_singles_with_two_players_rejects(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player1, $player2] = $context->createPlayers(2);

        $this->expectException(ValidationException::class);

        try {
            app(PersistCompetitionEntryAction::class)([
                'competition_id' => $competition->id,
                'player_ids' => [$player1->id, $player2->id],
            ]);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('player_ids', $exception->errors());

            throw $exception;
        }
    }

    public function test_nonexistent_player_rejects_with_functional_error(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player] = $context->createPlayers(1);

        $this->expectException(ValidationException::class);

        try {
            app(PersistCompetitionEntryAction::class)([
                'competition_id' => $competition->id,
                'player_ids' => [$player->id, 999999],
            ]);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('player_ids', $exception->errors());
            $this->assertStringContainsString('no existen', $exception->errors()['player_ids'][0]);

            throw $exception;
        }
    }

    public function test_player_already_in_another_pair_of_same_competition_rejects(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2, $player3] = $context->createPlayers(3);

        $context->registerPair($competition, $player1, $player2);

        $this->expectException(ValidationException::class);

        try {
            $context->registerPair($competition, $player1, $player3);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('player_ids', $exception->errors());
            $this->assertStringContainsString('ya está inscripto', $exception->errors()['player_ids'][0]);

            throw $exception;
        }
    }

    public function test_same_player_in_different_competitions_is_allowed(): void
    {
        $context = $this->tournamentContext();
        $competitionA = $context->createDoublesCompetition();
        $competitionB = $context->createDoublesCompetition();
        [$player1, $player2, $player3] = $context->createPlayers(3);

        $context->registerPair($competitionA, $player1, $player2);
        $context->registerPair($competitionB, $player1, $player3);

        $this->assertDatabaseCount('competition_entries', 2);
        $this->assertSame(4, CompetitionEntryMember::query()
            ->whereIn('competition_id', [$competitionA->id, $competitionB->id])
            ->count());
        $this->assertSame(2, CompetitionEntryMember::query()
            ->where('player_id', $player1->id)
            ->count());
    }

    public function test_persistence_failure_does_not_leave_incomplete_entry(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2] = $context->createPlayers(2);

        $threw = false;

        try {
            DB::transaction(function () use ($competition, $player1, $player2): void {
                app(PersistCompetitionEntryAction::class)([
                    'competition_id' => $competition->id,
                    'player_ids' => [$player1->id, $player2->id],
                ]);

                throw new \RuntimeException('Simulated failure after registration');
            });
        } catch (\RuntimeException) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertDatabaseCount('competition_entries', 0);
        $this->assertDatabaseCount('competition_entry_members', 0);
    }

    public function test_singles_display_name_is_correct(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $player->update(['first_name' => 'Carlos', 'last_name' => 'Pérez']);

        $entry = $context->registerPlayer($competition, $player->fresh());
        $entry->load('members.player');

        $this->assertSame('Carlos Pérez', CompetitionEntryDisplayName::for($entry));
    }

    public function test_doubles_display_name_is_correct(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2] = $context->createPlayers(2);
        $player1->update(['first_name' => 'Carlos', 'last_name' => 'Pérez']);
        $player2->update(['first_name' => 'Juan', 'last_name' => 'Gómez']);

        $entry = $context->registerPair($competition, $player1->fresh(), $player2->fresh());
        $entry->load('members.player');

        $this->assertSame('Carlos Pérez / Juan Gómez', CompetitionEntryDisplayName::for($entry));
    }

    public function test_existing_singles_flow_still_creates_one_member(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);

        $context->registerPlayerViaApi($competition, $player)->assertCreated();

        $this->assertDatabaseCount('competition_entries', 1);
        $this->assertDatabaseCount('competition_entry_members', 1);

        $member = CompetitionEntryMember::query()->sole();

        $this->assertSame($player->id, $member->player_id);
        $this->assertSame(1, $member->member_order);
    }

    public function test_both_player_id_and_player_ids_simultaneously_rejects(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2] = $context->createPlayers(2);

        $this->expectException(ValidationException::class);

        try {
            app(PersistCompetitionEntryAction::class)([
                'competition_id' => $competition->id,
                'player_id' => $player1->id,
                'player_ids' => [$player1->id, $player2->id],
            ]);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('player_id', $exception->errors());
            $this->assertArrayHasKey('player_ids', $exception->errors());

            throw $exception;
        }
    }

    public function test_singles_helpers_throw_on_doubles_entry(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2] = $context->createPlayers(2);

        $entry = $context->registerPair($competition, $player1, $player2);
        $entry->load('competition');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('singles');

        $entry->singlesMember();
    }
}
