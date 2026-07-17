<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\ActsAsKeycloakUser;
use Tests\Support\TournamentTestContext;

abstract class TestCase extends BaseTestCase
{
    use ActsAsKeycloakUser;
    use RefreshDatabase;

    protected function tournamentContext(): TournamentTestContext
    {
        return new TournamentTestContext($this);
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, string>
     */
    protected function authHeaders(array $roles = ['organizer']): array
    {
        return $this->keycloakAuthHeaders($roles);
    }
}
