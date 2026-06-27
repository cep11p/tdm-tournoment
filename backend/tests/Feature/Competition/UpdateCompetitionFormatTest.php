<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionFormat;
use Tests\TestCase;

class UpdateCompetitionFormatTest extends TestCase
{
    public function test_blocks_format_change_when_competition_has_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createGroupWithPlayers($competition, $players);

        $response = $context->updateCompetitionViaApi($competition, [
            'format' => CompetitionFormat::KnockoutDirect->value,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['format']);
    }

    public function test_allows_format_change_when_competition_is_pristine(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $response = $context->updateCompetitionViaApi($competition, [
            'format' => CompetitionFormat::KnockoutDirect->value,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.format', CompetitionFormat::KnockoutDirect->value)
            ->assertJsonPath('data.has_group_stage', false);
    }
}
