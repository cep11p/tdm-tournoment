<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionFormat;
use Tests\TestCase;

class KnockoutDirectFormatGuardTest extends TestCase
{
    public function test_does_not_allow_random_group_generation(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $response = $context->generateRandomGroups($competition, groupsCount: 2);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);
    }

    public function test_does_not_allow_manual_group_creation(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();

        $response = $this->postJson($context->apiUrl("competitions/{$competition->id}/groups"), [
            'name' => 'Grupo A',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);
    }

    public function test_does_not_allow_round_robin_generation(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['format' => CompetitionFormat::KnockoutDirect]);

        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);

        $group = $context->createGroupWithPlayers($competition, $players, 'Grupo legacy');

        $response = $context->generateRoundRobin($group);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition']);
    }
}
