<?php

namespace Tests\Feature\Competition;

use App\Models\Category;
use App\Models\Competition;
use App\Models\Tournament;
use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use App\Enums\TournamentStatus;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CompetitionCategoryTest extends TestCase
{
    public function test_creates_competition_with_category_id_and_syncs_legacy_category(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $tournament = $competition->tournament;
        $category = Category::query()->where('slug', 'segunda')->firstOrFail();

        $response = $context->createCompetitionViaApi($tournament->id, [
            'category_id' => $category->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.category', 'segunda')
            ->assertJsonPath('data.category_ref.slug', 'segunda');

        $this->assertDatabaseHas('competitions', [
            'category_id' => $category->id,
            'category' => 'segunda',
        ]);
    }

    public function test_accepts_legacy_category_string_on_create(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createCompetition()->tournament;
        $cuartaId = Category::query()->where('slug', 'cuarta')->value('id');

        $response = $this->postJson(
            $context->apiUrl("tournaments/{$tournament->id}/competitions"),
            [
                'name' => 'Singles Cuarta',
                'category' => 'cuarta',
                'type' => 'singles',
                'format' => 'groups_knockout',
                'points_per_set' => 11,
            ],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.category_id', $cuartaId)
            ->assertJsonPath('data.category', 'cuarta');
    }

    public function test_leaves_category_id_null_for_unknown_legacy_category_string(): void
    {
        $context = $this->tournamentContext();
        $tournament = $context->createCompetition()->tournament;

        $response = $this->postJson(
            $context->apiUrl("tournaments/{$tournament->id}/competitions"),
            [
                'name' => 'Singles Custom',
                'category' => 'singles-custom-slug',
                'type' => 'singles',
                'format' => 'groups_knockout',
                'points_per_set' => 11,
            ],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_unknown_legacy_category_on_direct_create_remains_without_category_id(): void
    {
        $tournament = Tournament::query()->create([
            'name' => 'Torneo Legacy',
            'location' => 'Club Test',
            'start_date' => Carbon::today()->toDateString(),
            'status' => TournamentStatus::Draft,
        ]);

        $competition = Competition::query()->create([
            'tournament_id' => $tournament->id,
            'name' => 'Singles Custom',
            'type' => CompetitionType::Singles,
            'category' => 'singles-custom-slug',
            'format' => CompetitionFormat::GroupsKnockout,
            'sets_to_win' => 3,
            'points_per_set' => 11,
            'group_stage_best_of' => 5,
            'knockout_stage_best_of' => 5,
            'semifinal_best_of' => 7,
            'final_best_of' => 7,
        ]);

        $this->assertNull($competition->fresh()->category_id);
    }

    public function test_updates_competition_category_via_legacy_string_mapping(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();
        $context->finishGame($setup['game'], $setup['playerOne'])->assertOk();

        $response = $context->updateCompetitionViaApi($setup['competition'], [
            'name' => 'Nombre actualizado',
            'category' => 'segunda',
        ]);

        $segundaId = Category::query()->where('slug', 'segunda')->value('id');

        $response
            ->assertOk()
            ->assertJsonPath('data.name', 'Nombre actualizado')
            ->assertJsonPath('data.category', 'segunda')
            ->assertJsonPath('data.category_id', $segundaId)
            ->assertJsonPath('data.is_structure_editable', false);
    }
}
