<?php

namespace Tests\Feature\Group;

use App\Enums\AuditAction;
use App\Enums\CompetitionType;
use App\Enums\TeamTieModality;
use App\Enums\TeamTieStatus;
use App\Models\Game;
use App\Models\TeamTie;
use App\Models\TeamTieFormat;
use App\Support\Competition\TeamCompetitionStructureGuard;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class GroupTeamRoundRobinTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_team_tie_format_slots_are_ordered(): void
    {
        $context = $this->tournamentContext();
        $format = $context->createTeamTieFormat();

        $this->assertSame(5, $format->slots->count());
        $this->assertSame([1, 2, 3, 4, 5], $format->slots->pluck('slot_order')->all());
        $this->assertSame(
            [
                TeamTieModality::Singles,
                TeamTieModality::Singles,
                TeamTieModality::Doubles,
                TeamTieModality::Singles,
                TeamTieModality::Singles,
            ],
            $format->slots->pluck('modality')->all(),
        );
    }

    public function test_team_competition_requires_team_tie_format_id_on_create(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();

        $context->createCompetitionViaApi($tournament->id, [
            'type' => 'team',
            'team_size' => 4,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['team_tie_format_id']);
    }

    public function test_singles_competition_rejects_team_tie_format_id(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();
        $format = $context->createTeamTieFormat();

        $context->createCompetitionViaApi($tournament->id, [
            'team_tie_format_id' => $format->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['team_tie_format_id']);
    }

    public function test_four_teams_generate_six_team_ties(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 4, 4);
        $group = $context->createGroupWithEntries($competition, $entries);

        $context->generateTeamRoundRobin($group)->assertCreated();

        $teamTies = TeamTie::query()->where('group_id', $group->id)->get();

        $this->assertSame(6, $teamTies->count());
        $this->assertSame(0, Game::query()->where('group_id', $group->id)->count());
    }

    public function test_three_teams_generate_three_team_ties_without_bye_rows(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 3, 4);
        $group = $context->createGroupWithEntries($competition, $entries);

        $context->generateTeamRoundRobin($group)->assertCreated();

        $teamTies = TeamTie::query()->where('group_id', $group->id)->get();

        $this->assertSame(3, $teamTies->count());
        $this->assertTrue($teamTies->every(fn (TeamTie $teamTie): bool => ! $teamTie->is_bye));
    }

    public function test_team_tie_pairings_are_unique_bidirectionally(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 4, 4);
        $group = $context->createGroupWithEntries($competition, $entries);

        $context->generateTeamRoundRobin($group)->assertCreated();

        $pairings = TeamTie::query()
            ->where('group_id', $group->id)
            ->get()
            ->map(function (TeamTie $teamTie): string {
                $entryIds = [(int) $teamTie->entry1_id, (int) $teamTie->entry2_id];
                sort($entryIds);

                return implode('-', $entryIds);
            });

        $this->assertSame($pairings->count(), $pairings->unique()->count());
    }

    public function test_team_tie_resource_shape(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);

        $context->generateTeamRoundRobin($group)->assertCreated();

        $response = $context->listGroupTeamTies($group);

        $response
            ->assertOk()
            ->assertJsonPath('data.0.status', TeamTieStatus::Pending->value)
            ->assertJsonPath('data.0.is_bye', false)
            ->assertJsonPath('data.0.entry1.display_name', 'Equipo 1')
            ->assertJsonPath('data.0.entry2.display_name', 'Equipo 2')
            ->assertJsonPath('data.0.format.name', 'Copa 5')
            ->assertJsonPath('data.0.format.victories_required', 3)
            ->assertJsonPath('data.0.group_round', 1)
            ->assertJsonPath('data.0.group_match', 1);
    }

    public function test_duplicate_team_round_robin_generation_is_blocked(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);

        $context->generateTeamRoundRobin($group)->assertCreated();
        $context->generateTeamRoundRobin($group)->assertUnprocessable();
    }

    public function test_team_format_and_roster_are_locked_after_schedule_generated(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $newFormat = $context->createTeamTieFormat('Otro formato');

        $context->generateTeamRoundRobin($group)->assertCreated();

        $context->updateCompetitionViaApi($competition, [
            'team_tie_format_id' => $newFormat->id,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.team_tie_format_id.0', TeamCompetitionStructureGuard::FORMAT_LOCK_MESSAGE);

        $extraPlayers = $context->createPlayers(4);
        $context->registerTeamViaApi(
            $competition,
            'Equipo extra',
            array_map(fn ($player) => $player->id, $extraPlayers),
        )
            ->assertUnprocessable()
            ->assertJsonPath('errors.competition.0', TeamCompetitionStructureGuard::REGISTRATIONS_LOCK_MESSAGE);
    }

    public function test_set_group_entry_status_is_blocked_when_team_ties_exist(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);

        $context->generateTeamRoundRobin($group)->assertCreated();

        $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'competition_entry_id' => $entries[0]->id,
            'status' => 'withdrawn',
            'reason' => 'injury',
        ], $this->authHeaders(['organizer']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.group.0', 'No se puede cambiar el estado del equipo porque ya hay enfrentamientos generados.');
    }

    public function test_competition_status_resolver_uses_team_ties(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'group_stage_pending');

        $context->generateTeamRoundRobin($group)->assertCreated();

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'group_stage_in_progress');
    }

    public function test_random_group_generation_for_team_creates_team_ties(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $context->registerTeams($competition, 4, 4);

        $context->generateRandomGroups($competition, 1)->assertCreated();

        $this->assertGreaterThan(0, TeamTie::query()->where('competition_id', $competition->id)->count());
        $this->assertSame(0, Game::query()->where('competition_id', $competition->id)->whereNotNull('group_id')->count());
    }

    public function test_team_round_robin_audit_uses_schedule_type_team_tie(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);

        $context->generateTeamRoundRobin($group)->assertCreated();

        $activity = Activity::query()->latest('id')->first();

        $this->assertSame(AuditAction::GROUPS_ROUND_ROBIN_GENERATED->value, $activity->description);
        $this->assertSame('team_tie', data_get($activity->properties, 'summary.schedule_type'));
        $this->assertSame(1, data_get($activity->properties, 'summary.team_ties_created'));
    }

    public function test_singles_round_robin_remains_unchanged(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $context->generateRoundRobin($group)->assertCreated();

        $this->assertSame(6, Game::query()->where('group_id', $group->id)->count());
        $this->assertSame(0, TeamTie::query()->where('group_id', $group->id)->count());
    }

    public function test_team_tie_formats_index_returns_active_formats(): void
    {
        $context = $this->tournamentContext();
        $context->createTeamTieFormat();

        $this->getJson('/api/v1/team-tie-formats')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Copa 5')
            ->assertJsonPath('data.0.slots.0.modality', TeamTieModality::Singles->value);
    }
}
