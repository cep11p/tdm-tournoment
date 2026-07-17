<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Models\Bracket;
use App\Models\Game;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class BracketOperationsAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
        $this->resetKeycloakClock();
        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_create_bracket_creates_exactly_one_activity(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        Activity::query()->delete();

        $context->createBracket($setup['competition'])->assertCreated();

        $this->assertSame(1, $this->bracketAuditCount());

        $activity = Activity::query()->sole();

        $this->assertSame(AuditAction::BRACKET_CREATED->value, $activity->description);
        $this->assertSame('bracket', $activity->log_name);
    }

    public function test_create_bracket_does_not_create_one_activity_per_game(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        Activity::query()->delete();

        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $gameCount = Game::query()->where('bracket_id', $bracket->id)->count();

        $this->assertGreaterThan(1, $gameCount);
        $this->assertSame(1, $this->bracketAuditCount());
    }

    public function test_create_bracket_metadata_contains_size_byes_and_games(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        Activity::query()->delete();

        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $activity = Activity::query()->sole();

        $this->assertSame($bracket->id, data_get($activity->properties, 'context.bracket_id'));
        $this->assertSame($bracket->id, data_get($activity->properties, 'new.bracket_id'));
        $this->assertSame($bracket->bracket_size, data_get($activity->properties, 'new.bracket_size'));
        $this->assertSame(1, data_get($activity->properties, 'new.round'));
        $this->assertSame(4, data_get($activity->properties, 'summary.qualified_players'));
        $this->assertSame($bracket->bracket_size, data_get($activity->properties, 'summary.bracket_size'));
        $this->assertSame($bracket->byes_count, data_get($activity->properties, 'summary.byes_count'));
        $this->assertSame(2, data_get($activity->properties, 'summary.games_created'));
    }

    public function test_advance_round_creates_exactly_one_activity(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();
        $context->finishGame($semifinals[1], $setup['playerThree'])->assertOk();

        Activity::query()->delete();

        $context->generateBracketNextRound($bracket)->assertCreated();

        $this->assertSame(1, Activity::query()->count());

        $activity = Activity::query()->sole();

        $this->assertSame(AuditAction::BRACKET_ROUND_ADVANCED->value, $activity->description);
        $this->assertSame('bracket', $activity->log_name);
        $this->assertSame(1, data_get($activity->properties, 'old.current_round'));
        $this->assertSame(2, data_get($activity->properties, 'new.generated_round'));
        $this->assertSame(1, data_get($activity->properties, 'summary.games_created'));
        $this->assertSame(2, data_get($activity->properties, 'summary.players_advanced'));
    }

    public function test_unprepared_bracket_error_does_not_create_activity(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();

        Activity::query()->delete();

        $context->generateBracketNextRound($bracket)->assertUnprocessable();

        $this->assertDatabaseCount('activity_log', 0);
    }

    private function bracketAuditCount(): int
    {
        return Activity::query()
            ->where('description', AuditAction::BRACKET_CREATED->value)
            ->count();
    }
}
