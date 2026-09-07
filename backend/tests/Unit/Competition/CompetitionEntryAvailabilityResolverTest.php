<?php

namespace Tests\Unit\Competition;

use App\Enums\CompetitionEntryStatus;
use App\Enums\CompetitionType;
use App\Models\CompetitionEntry;
use App\Support\Competition\CompetitionEntryAvailabilityResolver;
use Tests\TestCase;

class CompetitionEntryAvailabilityResolverTest extends TestCase
{
    public function test_singles_ready_when_member_is_checked_in(): void
    {
        $entry = $this->singlesEntry(checkedIn: true);

        $availability = CompetitionEntryAvailabilityResolver::forEntry($entry, CompetitionType::Singles);

        $this->assertSame('ready', $availability['kind']);
        $this->assertSame('Presente', $availability['label']);
        $this->assertSame(1, $availability['present']);
        $this->assertSame(1, $availability['total']);
    }

    public function test_singles_pending_when_member_is_not_checked_in(): void
    {
        $entry = $this->singlesEntry(checkedIn: false);

        $availability = CompetitionEntryAvailabilityResolver::forEntry($entry, CompetitionType::Singles);

        $this->assertSame('pending', $availability['kind']);
        $this->assertSame('Pendiente', $availability['label']);
        $this->assertSame(0, $availability['present']);
        $this->assertSame(1, $availability['total']);
    }

    public function test_doubles_ready_when_both_members_are_present(): void
    {
        $entry = $this->doublesEntry(presentCount: 2);

        $availability = CompetitionEntryAvailabilityResolver::forEntry($entry, CompetitionType::Doubles);

        $this->assertSame('ready', $availability['kind']);
        $this->assertSame('Pareja presente', $availability['label']);
        $this->assertSame(2, $availability['present']);
        $this->assertSame(2, $availability['total']);
    }

    public function test_doubles_incomplete_when_one_member_is_present(): void
    {
        $entry = $this->doublesEntry(presentCount: 1);

        $availability = CompetitionEntryAvailabilityResolver::forEntry($entry, CompetitionType::Doubles);

        $this->assertSame('incomplete', $availability['kind']);
        $this->assertSame('Pareja incompleta', $availability['label']);
        $this->assertSame(1, $availability['present']);
    }

    public function test_doubles_incomplete_when_none_are_present(): void
    {
        $entry = $this->doublesEntry(presentCount: 0);

        $availability = CompetitionEntryAvailabilityResolver::forEntry($entry, CompetitionType::Doubles);

        $this->assertSame('incomplete', $availability['kind']);
        $this->assertSame('Pendientes', $availability['label']);
        $this->assertSame(0, $availability['present']);
    }

    public function test_team_ready_when_all_members_are_present(): void
    {
        $entry = $this->teamEntry(presentCount: 4, total: 4);

        $availability = CompetitionEntryAvailabilityResolver::forEntry($entry, CompetitionType::Team);

        $this->assertSame('ready', $availability['kind']);
        $this->assertSame('4 de 4 presentes', $availability['label']);
    }

    public function test_team_partial_when_some_members_are_present(): void
    {
        $entry = $this->teamEntry(presentCount: 3, total: 4);

        $availability = CompetitionEntryAvailabilityResolver::forEntry($entry, CompetitionType::Team);

        $this->assertSame('partial', $availability['kind']);
        $this->assertSame('3 de 4 presentes', $availability['label']);
        $this->assertSame(3, $availability['present']);
        $this->assertSame(4, $availability['total']);
    }

    public function test_inactive_entry_is_not_rehabilitated_by_presence(): void
    {
        $entry = $this->singlesEntry(checkedIn: true);
        $entry->status = CompetitionEntryStatus::Withdrawn;

        $availability = CompetitionEntryAvailabilityResolver::forEntry($entry, CompetitionType::Singles);

        $this->assertSame('inactive', $availability['kind']);
        $this->assertSame('Retirado', $availability['label']);
        $this->assertSame(1, $availability['present']);
    }

    private function singlesEntry(bool $checkedIn): CompetitionEntry
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        [$player] = $context->createPlayers(1);
        $entry = $context->registerPlayer($competition, $player);

        if ($checkedIn) {
            $entry->members()->first()?->update(['checked_in_at' => now()]);
        }

        return $entry->fresh(['members']);
    }

    private function doublesEntry(int $presentCount): CompetitionEntry
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        [$player1, $player2] = $context->createPlayers(2);
        $entry = $context->registerPair($competition, $player1, $player2);

        $entry->load('members');
        foreach ($entry->members->take($presentCount) as $member) {
            $member->update(['checked_in_at' => now()]);
        }

        return $entry->fresh(['members']);
    }

    private function teamEntry(int $presentCount, int $total): CompetitionEntry
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition($total);
        $players = $context->createPlayers($total);
        $entry = $context->registerTeam(
            $competition,
            'Andes',
            array_map(fn ($player) => $player->id, $players),
        );

        $entry->load('members');
        foreach ($entry->members->take($presentCount) as $member) {
            $member->update(['checked_in_at' => now()]);
        }

        return $entry->fresh(['members']);
    }
}
