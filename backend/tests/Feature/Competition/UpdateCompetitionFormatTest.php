<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionFormat;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Support\Competition\CompetitionStructureGuard;
use Tests\TestCase;

class UpdateCompetitionFormatTest extends TestCase
{
    public function test_allows_format_change_when_competition_has_groups_without_real_activity(): void
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
            ->assertOk()
            ->assertJsonPath('data.format', CompetitionFormat::KnockoutDirect->value)
            ->assertJsonPath('data.has_group_stage', false);
    }

    public function test_blocks_format_change_when_competition_has_real_activity(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createPendingSinglesGame();
        $context->finishGame($setup['game'], $setup['playerOne'])->assertOk();

        $response = $context->updateCompetitionViaApi($setup['competition'], [
            'format' => CompetitionFormat::KnockoutDirect->value,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['format'])
            ->assertJsonPath('errors.format.0', CompetitionStructureGuard::LOCK_MESSAGE);
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
