<?php

namespace Tests\Feature\Competition;

use App\Enums\TournamentStatus;
use App\Models\Competition;
use App\Models\Tournament;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CompetitionQualifiedPerGroupTest extends TestCase
{
    public function test_created_competition_defaults_qualified_per_group_to_two(): void
    {
        $context = $this->tournamentContext();
        $tournament = Tournament::query()->create([
            'name' => 'Torneo Test',
            'location' => 'Club Test',
            'start_date' => Carbon::today()->toDateString(),
            'status' => TournamentStatus::Draft,
        ]);

        $response = $context->createCompetitionViaApi($tournament->id);

        $response
            ->assertCreated()
            ->assertJsonPath('data.qualified_per_group', 2);

        $competitionId = (int) $response->json('data.id');
        $competition = Competition::query()->findOrFail($competitionId);

        $this->assertSame(2, $competition->qualified_per_group);
    }

    public function test_created_competition_persists_custom_qualified_per_group(): void
    {
        $context = $this->tournamentContext();
        $tournament = Tournament::query()->create([
            'name' => 'Torneo Test',
            'location' => 'Club Test',
            'start_date' => Carbon::today()->toDateString(),
            'status' => TournamentStatus::Draft,
        ]);

        $response = $context->createCompetitionViaApi($tournament->id, [
            'qualified_per_group' => 1,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.qualified_per_group', 1);

        $competitionId = (int) $response->json('data.id');
        $competition = Competition::query()->findOrFail($competitionId);

        $this->assertSame(1, $competition->qualified_per_group);
    }

    public function test_allows_updating_qualified_per_group_before_bracket_exists(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $response = $context->updateCompetitionViaApi($competition, [
            'qualified_per_group' => 3,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.qualified_per_group', 3);

        $this->assertSame(3, $competition->fresh()->qualified_per_group);
    }

    public function test_rejects_updating_qualified_per_group_when_structure_is_locked(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $context->createBracket($setup['competition'])->assertCreated();

        $response = $context->updateCompetitionViaApi($setup['competition'], [
            'qualified_per_group' => 1,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['qualified_per_group']);

        $this->assertSame(2, $setup['competition']->fresh()->qualified_per_group);
    }

    public function test_allows_updating_qualified_per_group_when_bracket_has_only_byes_and_pending_games(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $response = $context->updateCompetitionViaApi($competition, [
            'qualified_per_group' => 1,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.qualified_per_group', 1)
            ->assertJsonPath('data.is_structure_editable', true);
    }

    public function test_allows_updating_other_fields_when_bracket_exists(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $context->createBracket($setup['competition'])->assertCreated();

        $response = $context->updateCompetitionViaApi($setup['competition'], [
            'name' => 'Singles Actualizado',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.name', 'Singles Actualizado');

        $this->assertSame('Singles Actualizado', $setup['competition']->fresh()->name);
    }
}
