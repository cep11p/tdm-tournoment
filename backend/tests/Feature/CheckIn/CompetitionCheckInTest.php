<?php

namespace Tests\Feature\CheckIn;

use App\Enums\CompetitionEntryStatus;
use App\Enums\TournamentStatus;
use App\Models\Bracket;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\GroupEntry;
use App\Support\Tournament\TournamentLifecycleGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompetitionCheckInTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_get_read_model_is_public(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $context->registerPlayer($competition, $player);

        $this->flushHeaders();

        $this->getJson($context->apiUrl("competitions/{$competition->id}/check-in"))
            ->assertOk();
    }

    public function test_get_read_model_exposes_member_id_distinct_from_player_id(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $player = $players[2];
        $entry = $context->registerPlayer($competition, $player);
        $member = $entry->members()->firstOrFail();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/check-in"))
            ->assertOk()
            ->assertJsonPath('data.competition.id', $competition->id)
            ->assertJsonPath('data.competition.type', 'singles')
            ->assertJsonPath('data.tournament_finished', false)
            ->assertJsonPath('data.summary.entries', 1)
            ->assertJsonPath('data.summary.members', 1)
            ->assertJsonPath('data.summary.checked_in_members', 0)
            ->assertJsonPath('data.summary.pending_members', 1);

        $payloadMember = $response->json('data.entries.0.members.0');

        $this->assertSame($member->id, $payloadMember['id']);
        $this->assertSame($player->id, $payloadMember['player_id']);
        $this->assertNotSame($payloadMember['id'], $payloadMember['player_id']);
        $this->assertFalse($payloadMember['checked_in']);
        $this->assertNull($payloadMember['checked_in_at']);
        $this->assertSame('pending', $response->json('data.entries.0.availability.kind'));
        $this->assertSame('Pendiente', $response->json('data.entries.0.availability.label'));
    }

    public function test_singles_ready_after_check_in(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $entry = $context->registerPlayer($competition, $player);
        $member = $entry->members()->firstOrFail();

        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/members/{$member->id}"))
            ->assertOk()
            ->assertJsonPath('data.id', $member->id)
            ->assertJsonPath('data.player_id', $player->id)
            ->assertJsonPath('data.checked_in', true);

        $this->getJson($context->apiUrl("competitions/{$competition->id}/check-in"))
            ->assertOk()
            ->assertJsonPath('data.summary.checked_in_members', 1)
            ->assertJsonPath('data.summary.pending_members', 0)
            ->assertJsonPath('data.entries.0.availability.kind', 'ready')
            ->assertJsonPath('data.entries.0.availability.label', 'Presente');
    }

    public function test_doubles_incomplete_and_ready(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2] = $context->createPlayers(2);
        $entry = $context->registerPair($competition, $player1, $player2);
        $members = $entry->members()->orderBy('member_order')->get();

        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/members/{$members[0]->id}"))
            ->assertOk();

        $this->getJson($context->apiUrl("competitions/{$competition->id}/check-in"))
            ->assertOk()
            ->assertJsonPath('data.entries.0.availability.kind', 'incomplete')
            ->assertJsonPath('data.entries.0.availability.label', 'Pareja incompleta')
            ->assertJsonPath('data.entries.0.availability.present', 1)
            ->assertJsonPath('data.entries.0.availability.total', 2);

        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/members/{$members[1]->id}"))
            ->assertOk();

        $this->getJson($context->apiUrl("competitions/{$competition->id}/check-in"))
            ->assertOk()
            ->assertJsonPath('data.entries.0.availability.kind', 'ready')
            ->assertJsonPath('data.entries.0.availability.label', 'Pareja presente');
    }

    public function test_team_partial_and_ready(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $players = $context->createPlayers(4);
        $entry = $context->registerTeam(
            $competition,
            'Andes',
            array_map(fn ($player) => $player->id, $players),
        );
        $members = $entry->members()->orderBy('member_order')->get();

        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/members/{$members[0]->id}"))
            ->assertOk();
        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/members/{$members[1]->id}"))
            ->assertOk();
        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/members/{$members[2]->id}"))
            ->assertOk();

        $this->getJson($context->apiUrl("competitions/{$competition->id}/check-in"))
            ->assertOk()
            ->assertJsonPath('data.entries.0.display_name', 'Andes')
            ->assertJsonPath('data.entries.0.availability.kind', 'partial')
            ->assertJsonPath('data.entries.0.availability.label', '3 de 4 presentes');

        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/members/{$members[3]->id}"))
            ->assertOk();

        $this->getJson($context->apiUrl("competitions/{$competition->id}/check-in"))
            ->assertOk()
            ->assertJsonPath('data.entries.0.availability.kind', 'ready')
            ->assertJsonPath('data.entries.0.availability.label', '4 de 4 presentes');
    }

    public function test_undo_check_in_is_idempotent(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $entry = $context->registerPlayer($competition, $player);
        $member = $entry->members()->firstOrFail();

        $this->deleteJson($context->apiUrl("competitions/{$competition->id}/check-in/members/{$member->id}"))
            ->assertOk()
            ->assertJsonPath('data.checked_in', false)
            ->assertJsonPath('data.checked_in_at', null);

        $this->assertNull($member->fresh()->checked_in_at);
    }

    public function test_check_in_is_idempotent_and_does_not_overwrite_timestamp(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $entry = $context->registerPlayer($competition, $player);
        $member = $entry->members()->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-09-07 12:00:00'));

        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/members/{$member->id}"))
            ->assertOk();

        $original = $member->fresh()->checked_in_at;
        $this->assertNotNull($original);

        Carbon::setTestNow(Carbon::parse('2026-09-07 13:00:00'));

        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/members/{$member->id}"))
            ->assertOk()
            ->assertJsonPath('data.checked_in', true);

        $this->assertTrue($original->equalTo($member->fresh()->checked_in_at));

        Carbon::setTestNow();
    }

    public function test_member_from_another_competition_returns_404(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $otherCompetition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $foreignEntry = $context->registerPlayer($otherCompetition, $player);
        $foreignMember = $foreignEntry->members()->firstOrFail();

        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/members/{$foreignMember->id}"))
            ->assertNotFound();
    }

    public function test_scorekeeper_cannot_mutate_check_in(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $entry = $context->registerPlayer($competition, $player);
        $member = $entry->members()->firstOrFail();

        $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/check-in/members/{$member->id}"),
            [],
            $this->authHeaders(['scorekeeper']),
        )->assertForbidden();
    }

    public function test_player_role_cannot_mutate_check_in(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $entry = $context->registerPlayer($competition, $player);
        $member = $entry->members()->firstOrFail();

        $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/check-in/members/{$member->id}"),
            [],
            $this->authHeaders(['player']),
        )->assertForbidden();
    }

    public function test_admin_can_mutate_check_in(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $entry = $context->registerPlayer($competition, $player);
        $member = $entry->members()->firstOrFail();

        $this->postJson(
            $context->apiUrl("competitions/{$competition->id}/check-in/members/{$member->id}"),
            [],
            $this->authHeaders(['admin']),
        )
            ->assertOk()
            ->assertJsonPath('data.checked_in', true);
    }

    public function test_guest_cannot_mutate_check_in(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $entry = $context->registerPlayer($competition, $player);
        $member = $entry->members()->firstOrFail();

        $this->flushHeaders();

        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/members/{$member->id}"))
            ->assertUnauthorized();
    }

    public function test_finished_tournament_blocks_mutations_and_allows_get(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $entry = $context->registerPlayer($competition, $player);
        $member = $entry->members()->firstOrFail();

        $competition->tournament->update([
            'status' => TournamentStatus::Finished,
            'closed_at' => now(),
        ]);

        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/members/{$member->id}"))
            ->assertUnprocessable()
            ->assertJsonPath('errors.tournament.0', TournamentLifecycleGuard::LOCK_MESSAGE);

        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/bulk"), [
            'action' => 'mark_all_present',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.tournament.0', TournamentLifecycleGuard::LOCK_MESSAGE);

        $this->getJson($context->apiUrl("competitions/{$competition->id}/check-in"))
            ->assertOk()
            ->assertJsonPath('data.tournament_finished', true);
    }

    public function test_bulk_mark_all_present_skips_inactive_entries_and_preserves_timestamps(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player1, $player2, $player3] = $context->createPlayers(3);
        $activePresent = $context->registerPlayer($competition, $player1);
        $activePending = $context->registerPlayer($competition, $player2);
        $withdrawn = $context->registerPlayer($competition, $player3);

        $presentMember = $activePresent->members()->firstOrFail();
        $pendingMember = $activePending->members()->firstOrFail();
        $withdrawnMember = $withdrawn->members()->firstOrFail();

        Carbon::setTestNow(Carbon::parse('2026-09-07 10:00:00'));
        $presentMember->update(['checked_in_at' => now()]);
        $originalTimestamp = $presentMember->fresh()->checked_in_at;

        $withdrawn->update(['status' => CompetitionEntryStatus::Withdrawn]);

        Carbon::setTestNow(Carbon::parse('2026-09-07 11:00:00'));

        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/bulk"), [
            'action' => 'mark_all_present',
        ])
            ->assertOk()
            ->assertJsonPath('data.summary.checked_in_members', 2)
            ->assertJsonPath('data.summary.pending_members', 0);

        $this->assertTrue($originalTimestamp->equalTo($presentMember->fresh()->checked_in_at));
        $this->assertNotNull($pendingMember->fresh()->checked_in_at);
        $this->assertNull($withdrawnMember->fresh()->checked_in_at);
        $this->assertSame(CompetitionEntryStatus::Withdrawn, $withdrawn->fresh()->status);
    }

    public function test_bulk_clear_all_does_not_touch_inactive_entries(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player1, $player2] = $context->createPlayers(2);
        $active = $context->registerPlayer($competition, $player1);
        $disqualified = $context->registerPlayer($competition, $player2);

        $activeMember = $active->members()->firstOrFail();
        $disqualifiedMember = $disqualified->members()->firstOrFail();

        $activeMember->update(['checked_in_at' => now()]);
        $disqualifiedMember->update(['checked_in_at' => now()]);
        $disqualified->update(['status' => CompetitionEntryStatus::Disqualified]);

        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/bulk"), [
            'action' => 'clear_all',
        ])->assertOk();

        $this->assertNull($activeMember->fresh()->checked_in_at);
        $this->assertNotNull($disqualifiedMember->fresh()->checked_in_at);
        $this->assertSame(CompetitionEntryStatus::Disqualified, $disqualified->fresh()->status);
    }

    public function test_inactive_member_cannot_be_checked_in_individually(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $entry = $context->registerPlayer($competition, $player);
        $member = $entry->members()->firstOrFail();
        $entry->update(['status' => CompetitionEntryStatus::Withdrawn]);

        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/members/{$member->id}"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['member']);

        $this->assertNull($member->fresh()->checked_in_at);
    }

    public function test_check_in_does_not_change_entry_status_group_entry_games_or_bracket(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, [$players[0]]);

        $member = CompetitionEntryMember::query()
            ->where('competition_id', $competition->id)
            ->where('player_id', $players[0]->id)
            ->firstOrFail();

        $entryStatus = CompetitionEntry::query()->findOrFail($member->competition_entry_id)->status;
        $groupEntry = GroupEntry::query()
            ->where('group_id', $group->id)
            ->where('competition_entry_id', $member->competition_entry_id)
            ->firstOrFail();
        $groupEntrySnapshot = $groupEntry->only(['status', 'status_reason', 'status_notes', 'status_changed_at']);
        $gamesCount = Game::query()->where('competition_id', $competition->id)->count();
        $bracketCount = Bracket::query()->where('competition_id', $competition->id)->count();

        $this->postJson($context->apiUrl("competitions/{$competition->id}/check-in/members/{$member->id}"))
            ->assertOk();

        $this->assertSame($entryStatus, CompetitionEntry::query()->findOrFail($member->competition_entry_id)->status);
        $this->assertEquals(
            $groupEntrySnapshot,
            $groupEntry->fresh()->only(['status', 'status_reason', 'status_notes', 'status_changed_at']),
        );
        $this->assertSame($gamesCount, Game::query()->where('competition_id', $competition->id)->count());
        $this->assertSame($bracketCount, Bracket::query()->where('competition_id', $competition->id)->count());
        $this->assertSame(0, $bracketCount);
    }

    public function test_get_read_model_does_not_n_plus_one_members_and_players(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson($context->apiUrl("competitions/{$competition->id}/check-in"))
            ->assertOk()
            ->assertJsonPath('data.summary.members', 4);

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, $queryCount);
    }

    public function test_pending_summary_ignores_inactive_entries(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player1, $player2] = $context->createPlayers(2);
        $context->registerPlayer($competition, $player1);
        $withdrawn = $context->registerPlayer($competition, $player2);
        $withdrawn->update(['status' => CompetitionEntryStatus::Withdrawn]);

        $this->getJson($context->apiUrl("competitions/{$competition->id}/check-in"))
            ->assertOk()
            ->assertJsonPath('data.summary.entries', 2)
            ->assertJsonPath('data.summary.members', 2)
            ->assertJsonPath('data.summary.checked_in_members', 0)
            ->assertJsonPath('data.summary.pending_members', 1)
            ->assertJsonPath('data.entries.1.availability.kind', 'inactive');
    }
}
