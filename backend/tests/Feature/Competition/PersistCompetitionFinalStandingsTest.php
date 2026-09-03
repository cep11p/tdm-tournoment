<?php

namespace Tests\Feature\Competition;

use App\Actions\Competition\PersistCompetitionFinalStandingsAction;
use App\Enums\CompetitionFinalStandingSource;
use App\Enums\ThirdPlaceMode;
use App\Models\CompetitionFinalStanding;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PersistCompetitionFinalStandingsTest extends TestCase
{
    public function test_completed_knockout_auto_persists_final_standings(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $context->finishGame($competition->games()->first(), $players[0])->assertOk();

        $this->assertSame(2, $competition->finalStandings()->count());
        $this->assertSame(1, $competition->finalStandings()->where('position', 1)->count());
        $this->assertSame(1, $competition->finalStandings()->where('position', 2)->count());
        $this->assertSame(
            CompetitionFinalStandingSource::Final,
            $competition->finalStandings()->first()->source,
        );
    }

    public function test_persist_action_is_idempotent(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $context->completeCompetitionThroughFinal($competition);

        $first = $this->signature($competition);
        $rowsAfterFirst = $competition->finalStandings()->count();

        app(PersistCompetitionFinalStandingsAction::class)($competition->fresh());

        $this->assertSame(2, $rowsAfterFirst);
        $this->assertSame(2, $competition->finalStandings()->count());
        $this->assertSame($first, $this->signature($competition));
    }

    public function test_incomplete_competition_rejects_without_partial_rows(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        try {
            app(PersistCompetitionFinalStandingsAction::class)($competition);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertSame(
                PersistCompetitionFinalStandingsAction::INCOMPLETE_MESSAGE,
                $exception->errors()['competition'][0],
            );
        }

        $this->assertSame(0, CompetitionFinalStanding::query()->where('competition_id', $competition->id)->count());
    }

    public function test_coverage_matches_competition_entries_exactly_once(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Shared]);
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();
        $context->completeCompetitionThroughFinal($competition);

        $entryIds = $competition->entries()->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $standingIds = $competition->finalStandings()->pluck('competition_entry_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        $this->assertSame($entryIds, $standingIds);
        $this->assertSame(4, $competition->finalStandings()->count());
        $this->assertSame(4, $competition->finalStandings()->distinct('competition_entry_id')->count('competition_entry_id'));
    }

    /**
     * @return list<array{int, int, int, string, string}>
     */
    private function signature($competition): array
    {
        return $competition->finalStandings()
            ->orderBy('position')
            ->orderBy('competition_entry_id')
            ->get()
            ->map(fn (CompetitionFinalStanding $row): array => [
                (int) $row->competition_entry_id,
                (int) $row->position,
                (int) $row->position_range_end,
                $row->source instanceof CompetitionFinalStandingSource
                    ? $row->source->value
                    : (string) $row->source,
                (string) $row->display_name_snapshot,
            ])
            ->all();
    }
}
