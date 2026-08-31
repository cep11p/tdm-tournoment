<?php

namespace Tests\Feature\Registration;

use App\Enums\AuditAction;
use App\Enums\CompetitionType;
use App\Models\Competition;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TeamCompetitionRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_competition_type_accepts_team(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();
        $format = $context->createTeamTieFormat();

        $context->createCompetitionViaApi($tournament->id, [
            'name' => 'Interclubes',
            'type' => 'team',
            'team_size' => 4,
            'team_tie_format_id' => $format->id,
        ])->assertCreated()
            ->assertJsonPath('data.type', 'team')
            ->assertJsonPath('data.team_size', 4)
            ->assertJsonPath('data.team_tie_format_id', $format->id);
    }

    public function test_team_competition_requires_team_size(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();

        $context->createCompetitionViaApi($tournament->id, [
            'type' => 'team',
            'team_tie_format_id' => $context->createTeamTieFormat()->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['team_size']);
    }

    public function test_invalid_team_size_is_rejected(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createTournament();

        $context->createCompetitionViaApi($tournament->id, [
            'type' => 'team',
            'team_size' => 1,
            'team_tie_format_id' => $context->createTeamTieFormat()->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['team_size']);
    }

    public function test_singles_competition_does_not_require_team_size(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $this->assertNull($competition->team_size);
    }

    public function test_doubles_competition_does_not_require_team_size(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();

        $this->assertNull($competition->team_size);
    }

    public function test_register_team_persists_display_name_and_members(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $players = $context->createPlayers(4);

        $response = $context->registerTeamViaApi(
            $competition,
            'Club Andino A',
            array_map(fn ($player) => $player->id, $players),
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Club Andino A')
            ->assertJsonPath('data.player', null)
            ->assertJsonCount(4, 'data.members');

        $this->assertDatabaseHas('competition_entries', [
            'competition_id' => $competition->id,
            'display_name' => 'Club Andino A',
        ]);
    }

    public function test_register_team_preserves_member_order(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $players = $context->createPlayers(4);

        $orderedIds = [$players[3]->id, $players[1]->id, $players[0]->id, $players[2]->id];

        $response = $context->registerTeamViaApi($competition, 'Equipo Orden', $orderedIds);

        $response
            ->assertCreated()
            ->assertJsonPath('data.members.0.id', $players[3]->id)
            ->assertJsonPath('data.members.1.id', $players[1]->id)
            ->assertJsonPath('data.members.2.id', $players[0]->id)
            ->assertJsonPath('data.members.3.id', $players[2]->id);

        foreach ($orderedIds as $index => $playerId) {
            $this->assertDatabaseHas('competition_entry_members', [
                'competition_id' => $competition->id,
                'player_id' => $playerId,
                'member_order' => $index + 1,
            ]);
        }
    }

    public function test_register_team_rejects_roster_smaller_than_team_size(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $players = $context->createPlayers(3);

        $context->registerTeamViaApi(
            $competition,
            'Equipo Incompleto',
            array_map(fn ($player) => $player->id, $players),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids']);
    }

    public function test_register_team_rejects_roster_larger_than_team_size(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $players = $context->createPlayers(5);

        $context->registerTeamViaApi(
            $competition,
            'Equipo Grande',
            array_map(fn ($player) => $player->id, $players),
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids']);
    }

    public function test_register_team_rejects_duplicate_player_in_roster(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $players = $context->createPlayers(3);

        $context->registerTeamViaApi($competition, 'Equipo Duplicado', [
            $players[0]->id,
            $players[0]->id,
            $players[1]->id,
            $players[2]->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids.0']);
    }

    public function test_register_team_rejects_player_already_on_another_team(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $players = $context->createPlayers(5);

        $context->registerTeamViaApi(
            $competition,
            'Equipo A',
            [$players[0]->id, $players[1]->id, $players[2]->id, $players[3]->id],
        )->assertCreated();

        $context->registerTeamViaApi(
            $competition,
            'Equipo B',
            [$players[0]->id, $players[1]->id, $players[2]->id, $players[4]->id],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids']);
    }

    public function test_same_player_can_register_on_team_in_another_competition(): void
    {
        $context = $this->tournamentContext();
        $competitionA = $context->createTeamCompetition(2);
        $competitionB = $context->createTeamCompetition(2);
        $players = $context->createPlayers(2);

        $context->registerTeamViaApi(
            $competitionA,
            'Equipo A',
            [$players[0]->id, $players[1]->id],
        )->assertCreated();

        $context->registerTeamViaApi(
            $competitionB,
            'Equipo B',
            [$players[0]->id, $players[1]->id],
        )->assertCreated();
    }

    public function test_register_team_requires_name(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(2);
        $players = $context->createPlayers(2);

        $this->postJson($context->apiUrl("competitions/{$competition->id}/registrations"), [
            'player_ids' => [$players[0]->id, $players[1]->id],
        ], $this->authHeaders(['organizer']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_register_team_rejects_duplicate_team_name(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(2);
        $players = $context->createPlayers(4);

        $context->registerTeamViaApi(
            $competition,
            'Club Andino A',
            [$players[0]->id, $players[1]->id],
        )->assertCreated();

        $context->registerTeamViaApi(
            $competition,
            'Club Andino A',
            [$players[2]->id, $players[3]->id],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_bulk_team_registration_returns_422(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $players = $context->createPlayers(4);

        $this->postJson($context->apiUrl("competitions/{$competition->id}/registrations/bulk"), [
            'player_ids' => array_map(fn ($player) => $player->id, $players),
        ], $this->authHeaders(['organizer']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids']);
    }

    public function test_assign_team_entry_to_group_works(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(2);
        $players = $context->createPlayers(2);
        $entry = $context->registerTeam($competition, 'Club A', [$players[0]->id, $players[1]->id]);
        $group = $context->createGroup($competition);

        $context->assignEntryToGroupViaApi($group, $entry)
            ->assertCreated();

        $this->assertDatabaseHas('group_entries', [
            'group_id' => $group->id,
            'competition_entry_id' => $entry->id,
        ]);
    }

    public function test_assign_team_entry_to_group_rejects_player_id(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(2);
        $players = $context->createPlayers(2);
        $context->registerTeam($competition, 'Club A', [$players[0]->id, $players[1]->id]);
        $group = $context->createGroup($competition);

        $context->assignPlayerToGroupViaApi($group, $players[0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);
    }

    public function test_team_registration_creates_audit_log(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(2);
        $players = $context->createPlayers(2);

        $context->registerTeamViaApi(
            $competition,
            'Club Andino A',
            [$players[0]->id, $players[1]->id],
        )->assertCreated();

        $activity = Activity::query()
            ->where('description', AuditAction::REGISTRATION_CREATED->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('Club Andino A', data_get($activity->properties, 'summary.display_name'));
        $this->assertSame('Club Andino A', data_get($activity->properties, 'summary.team_name'));
    }

    public function test_expected_member_count_for_team(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);

        $this->assertSame(CompetitionType::Team, $competition->type);
        $this->assertSame(4, $competition->expectedMemberCount());
    }
}
