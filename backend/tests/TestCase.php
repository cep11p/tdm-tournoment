<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TournamentTestContext;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function tournamentContext(): TournamentTestContext
    {
        return new TournamentTestContext($this);
    }
}
