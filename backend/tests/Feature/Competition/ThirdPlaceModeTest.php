<?php

namespace Tests\Feature\Competition;

use App\Enums\GameStatus;
use App\Enums\ThirdPlaceMode;
use App\Models\Bracket;
use App\Models\Game;
use App\Models\Player;
use App\Models\Tournament;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ThirdPlaceModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_create_defaults_to_shared_when_mode_is_omitted(): void
    {
        $context = $this->tournamentContext();
        $tournament = Tournament::query()->findOrFail($context->createCompetition()->tournament_id);

        $response = $context->createCompetitionViaApi($tournament->id, [
            'name' => 'Compartida default',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.third_place_mode', ThirdPlaceMode::Shared->value)
            ->assertJsonPath('data.third_place_mode_label', ThirdPlaceMode::Shared->label());
    }

    public function test_create_accepts_explicit_none_and_playoff(): void
    {
        $context = $this->tournamentContext();
        $tournament = Tournament::query()->findOrFail($context->createCompetition()->tournament_id);

        $context->createCompetitionViaApi($tournament->id, [
            'name' => 'Sin tercero',
            'third_place_mode' => ThirdPlaceMode::None->value,
        ])->assertCreated()
            ->assertJsonPath('data.third_place_mode', ThirdPlaceMode::None->value);

        $context->createCompetitionViaApi($tournament->id, [
            'name' => 'Playoff reservado',
            'third_place_mode' => ThirdPlaceMode::Playoff->value,
        ])->assertCreated()
            ->assertJsonPath('data.third_place_mode', ThirdPlaceMode::Playoff->value);
    }

    public function test_rejects_invalid_third_place_mode(): void
    {
        $context = $this->tournamentContext();
        $tournament = Tournament::query()->findOrFail($context->createCompetition()->tournament_id);

        $context->createCompetitionViaApi($tournament->id, [
            'third_place_mode' => 'invalid',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['third_place_mode']);
    }

    public function test_update_third_place_mode_when_structure_is_editable(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::None]);
        $competition->refresh();

        Activity::query()->delete();

        $response = $context->updateCompetitionViaApi($competition, [
            'third_place_mode' => ThirdPlaceMode::Shared->value,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.third_place_mode', ThirdPlaceMode::Shared->value);

        $activity = Activity::query()->sole();

        $this->assertSame('none', data_get($activity->properties, 'old.third_place_mode'));
        $this->assertSame('shared', data_get($activity->properties, 'new.third_place_mode'));
    }

    public function test_rejects_third_place_mode_change_when_structure_is_locked(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::None]);
        $competition->refresh();

        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $game = Game::query()->where('group_id', $group->id)->sole();
        $context->finishGame($game, $players[0])->assertOk();

        $context->updateCompetitionViaApi($competition, [
            'third_place_mode' => ThirdPlaceMode::Shared->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['third_place_mode']);
    }
}
