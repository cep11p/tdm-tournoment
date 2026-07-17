<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class BracketAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
    }

    protected function tearDown(): void
    {
        $this->resetKeycloakClock();

        parent::tearDown();
    }

    public function test_bracket_show_remains_public_without_authentication(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket"))
            ->assertNotFound();
    }

    public function test_bracket_show_remains_public_when_bracket_exists(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $context->createBracket($setup['competition'])->assertCreated();

        $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/bracket"))
            ->assertOk();
    }

    public function test_create_bracket_requires_authentication(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $this->postJson($context->apiUrl("competitions/{$setup['competition']->id}/bracket"), [])
            ->assertUnauthorized();
    }

    public function test_create_bracket_forbidden_for_scorekeeper(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $context->createBracket($setup['competition'], roles: ['scorekeeper'])
            ->assertForbidden();
    }

    public function test_organizer_can_create_bracket(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $context->createBracket($setup['competition'])
            ->assertCreated();
    }

    public function test_advance_round_requires_authentication(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $bracketResponse = $context->createBracket($setup['competition'])->assertCreated();
        $bracketId = $bracketResponse->json('data.id');

        $this->postJson($context->apiUrl("brackets/{$bracketId}/next-round"), [])
            ->assertUnauthorized();
    }

    public function test_advance_round_forbidden_for_scorekeeper(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $bracketResponse = $context->createBracket($setup['competition'])->assertCreated();
        $bracketId = $bracketResponse->json('data.id');

        $context->generateBracketNextRound(
            \App\Models\Bracket::query()->findOrFail($bracketId),
            ['scorekeeper'],
        )->assertForbidden();
    }

    public function test_organizer_with_permission_receives_domain_422_when_bracket_not_ready(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: false);

        $context->createBracket($setup['competition'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group']);
    }
}
