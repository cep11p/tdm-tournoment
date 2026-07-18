<?php

namespace Tests\Feature\Tournament;

use App\Enums\TournamentStatus;
use Tests\TestCase;

class TournamentCloseAuthorizationTest extends TestCase
{
    public function test_admin_can_close_tournament(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);

        $context->closeTournament($setup['competition']->tournament, ['admin'])
            ->assertOk()
            ->assertJsonPath('data.status', TournamentStatus::Finished->value);
    }

    public function test_organizer_can_close_tournament(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);

        $context->closeTournament($setup['competition']->tournament, ['organizer'])
            ->assertOk();
    }

    public function test_scorekeeper_cannot_close_tournament(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);

        $context->closeTournament($setup['competition']->tournament, ['scorekeeper'])
            ->assertForbidden();
    }

    public function test_player_cannot_close_tournament(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);

        $context->closeTournament($setup['competition']->tournament, ['player'])
            ->assertForbidden();
    }

    public function test_guest_cannot_close_tournament(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->completeCompetitionThroughFinal($setup['competition']);

        $this->postJson($context->apiUrl("tournaments/{$setup['competition']->tournament_id}/close"))
            ->assertUnauthorized();
    }
}
