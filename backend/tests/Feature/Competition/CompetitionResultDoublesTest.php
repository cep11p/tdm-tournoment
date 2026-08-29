<?php

namespace Tests\Feature\Competition;

use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Enums\ThirdPlaceMode;
use App\Enums\TournamentStatus;
use App\Models\Bracket;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Support\Competition\CompetitionEntryDisplayName;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CompetitionResultDoublesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_singles_result_summary_keeps_legacy_id_and_name(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $result = $context->completeCompetitionThroughFinal($setup['competition']);

        $champion = $result['champion'];
        $runnerUp = $result['runner_up'];

        $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary.champion.id', $champion->id)
            ->assertJsonPath('data.result_summary.champion.name', trim("{$champion->first_name} {$champion->last_name}"))
            ->assertJsonPath('data.result_summary.runner_up.id', $runnerUp->id)
            ->assertJsonPath('data.result_summary.runner_up.name', trim("{$runnerUp->first_name} {$runnerUp->last_name}"));
    }

    public function test_singles_result_summary_includes_entry_fields(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $result = $context->completeCompetitionThroughFinal($setup['competition']);

        $championEntry = CompetitionEntry::query()
            ->where('competition_id', $setup['competition']->id)
            ->whereHas('members', fn ($query) => $query->where('player_id', $result['champion']->id))
            ->firstOrFail();

        $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary.champion.competition_entry_id', $championEntry->id)
            ->assertJsonPath('data.result_summary.champion.display_name', CompetitionEntryDisplayName::for($championEntry))
            ->assertJsonCount(1, 'data.result_summary.champion.members');
    }

    public function test_doubles_result_summary_champion_runner_up_and_members(): void
    {
        $context = $this->tournamentContext();
        $competition = $this->createDoublesKnockoutCompetition($context);
        $result = $context->completeDoublesCompetitionThroughFinal($competition, finishThirdPlace: false);

        $championEntry = $result['champion_entry'];
        $runnerUpEntry = $result['runner_up_entry'];

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary.champion.competition_entry_id', $championEntry->id)
            ->assertJsonPath('data.result_summary.champion.display_name', CompetitionEntryDisplayName::for($championEntry))
            ->assertJsonCount(2, 'data.result_summary.champion.members')
            ->assertJsonPath('data.result_summary.runner_up.competition_entry_id', $runnerUpEntry->id)
            ->assertJsonPath('data.result_summary.runner_up.display_name', CompetitionEntryDisplayName::for($runnerUpEntry))
            ->assertJsonCount(2, 'data.result_summary.runner_up.members');
    }

    public function test_doubles_result_summary_legacy_id_and_name_are_null(): void
    {
        $context = $this->tournamentContext();
        $competition = $this->createDoublesKnockoutCompetition($context);
        $context->completeDoublesCompetitionThroughFinal($competition, finishThirdPlace: false);

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary.champion.id', null)
            ->assertJsonPath('data.result_summary.champion.name', null)
            ->assertJsonPath('data.result_summary.runner_up.id', null)
            ->assertJsonPath('data.result_summary.runner_up.name', null);
    }

    public function test_doubles_playoff_third_and_fourth_place(): void
    {
        $context = $this->tournamentContext();
        $competition = $this->createDoublesKnockoutCompetition($context, ThirdPlaceMode::Playoff);

        $context->createBracket($competition)->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();

        foreach ($context->bracketGamesForRound($bracket, 1) as $game) {
            if ($game->is_bye) {
                continue;
            }

            $context->finishGameByEntryViaApi($game, (int) $game->entry1_id)->assertOk();
        }

        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)
            ->first(fn (Game $game): bool => $game->round === 'Final');
        $context->finishGameByEntryViaApi($final, (int) $final->entry1_id)->assertOk();

        $thirdPlace = Game::query()
            ->where('bracket_id', $bracket->id)
            ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
            ->firstOrFail();

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary.third_place', [])
            ->assertJsonPath('data.result_summary.third_place_game_id', $thirdPlace->id);

        $thirdWinnerEntryId = (int) $thirdPlace->entry1_id;
        $fourthEntryId = (int) $thirdPlace->entry2_id;

        $context->finishGameByEntryViaApi($thirdPlace, $thirdWinnerEntryId)->assertOk();

        $thirdWinnerEntry = CompetitionEntry::query()->findOrFail($thirdWinnerEntryId);
        $fourthEntry = CompetitionEntry::query()->findOrFail($fourthEntryId);

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonCount(1, 'data.result_summary.third_place')
            ->assertJsonPath('data.result_summary.third_place.0.competition_entry_id', $thirdWinnerEntryId)
            ->assertJsonPath('data.result_summary.third_place.0.display_name', CompetitionEntryDisplayName::for($thirdWinnerEntry))
            ->assertJsonPath('data.result_summary.third_place.0.id', null)
            ->assertJsonPath('data.result_summary.fourth_place.competition_entry_id', $fourthEntryId)
            ->assertJsonPath('data.result_summary.fourth_place.display_name', CompetitionEntryDisplayName::for($fourthEntry));
    }

    public function test_doubles_shared_third_place_from_semifinal_losers(): void
    {
        $context = $this->tournamentContext();
        $competition = $this->createDoublesKnockoutCompetition($context, ThirdPlaceMode::Shared);

        $context->createBracket($competition)->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $losers = [];

        foreach ($semifinals as $game) {
            $context->finishGameByEntryViaApi($game, (int) $game->entry1_id)->assertOk();
            $losers[] = (int) $game->entry2_id;
        }

        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)
            ->first(fn (Game $game): bool => $game->round === 'Final');
        $context->finishGameByEntryViaApi($final, (int) $final->entry1_id)->assertOk();

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonCount(2, 'data.result_summary.third_place')
            ->assertJsonPath('data.result_summary.third_place.0.competition_entry_id', $losers[0])
            ->assertJsonPath('data.result_summary.third_place.1.competition_entry_id', $losers[1]);
    }

    public function test_doubles_shared_third_place_empty_when_semifinal_has_bye(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Shared]);

        $players = $context->createPlayers(6);
        $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
        ]);

        $context->createBracket($competition)->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();

        foreach ($context->bracketGamesForRound($bracket, 1)->reject(fn (Game $game) => $game->is_bye) as $game) {
            $context->finishGameByEntryViaApi($game, (int) $game->entry1_id)->assertOk();
        }

        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->first();
        $context->finishGameByEntryViaApi($final, (int) $final->entry1_id)->assertOk();

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary.third_place', []);
    }

    public function test_doubles_final_correction_updates_champion_in_result_summary(): void
    {
        $context = $this->tournamentContext();
        $competition = $this->createDoublesKnockoutCompetition($context);
        $result = $context->completeDoublesCompetitionThroughFinal($competition, finishThirdPlace: false);

        $final = $result['final'];
        $newChampionEntryId = (int) $final->entry2_id;
        $newChampionEntry = CompetitionEntry::query()->findOrFail($newChampionEntryId);

        $context->correctResult($final->fresh(), 'correccion final doubles', [
            ['player1_score' => 0, 'player2_score' => 11],
        ])->assertOk();

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary.champion.competition_entry_id', $newChampionEntryId)
            ->assertJsonPath('data.result_summary.champion.display_name', CompetitionEntryDisplayName::for($newChampionEntry));
    }

    public function test_doubles_third_place_correction_updates_podium(): void
    {
        $context = $this->tournamentContext();
        $competition = $this->createDoublesKnockoutCompetition($context, ThirdPlaceMode::Playoff);
        $context->completeDoublesCompetitionThroughFinal($competition);

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $thirdPlace = Game::query()
            ->where('bracket_id', $bracket->id)
            ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
            ->firstOrFail();

        $newThirdEntryId = (int) $thirdPlace->entry2_id;
        $newThirdEntry = CompetitionEntry::query()->findOrFail($newThirdEntryId);
        $newFourthEntryId = (int) $thirdPlace->entry1_id;

        $context->correctResult($thirdPlace->fresh(), 'correccion tercer puesto doubles', [
            ['player1_score' => 0, 'player2_score' => 11],
        ])->assertOk();

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary.third_place.0.competition_entry_id', $newThirdEntryId)
            ->assertJsonPath('data.result_summary.third_place.0.display_name', CompetitionEntryDisplayName::for($newThirdEntry))
            ->assertJsonPath('data.result_summary.fourth_place.competition_entry_id', $newFourthEntryId);
    }

    public function test_close_tournament_with_completed_doubles_competition(): void
    {
        $context = $this->tournamentContext();
        $competition = $this->createDoublesKnockoutCompetition($context);
        $result = $context->completeDoublesCompetitionThroughFinal($competition);
        $tournament = $competition->tournament;

        $context->closeTournament($tournament)
            ->assertOk()
            ->assertJsonPath('data.status', TournamentStatus::Finished->value)
            ->assertJsonPath('data.results_summary.results.0.champion_entry_id', $result['champion_entry']->id)
            ->assertJsonPath('data.results_summary.results.0.champion_id', null)
            ->assertJsonPath(
                'data.results_summary.results.0.champion_display_name',
                CompetitionEntryDisplayName::for($result['champion_entry']),
            );
    }

    public function test_incomplete_doubles_competition_blocks_tournament_close(): void
    {
        $context = $this->tournamentContext();
        $competition = $this->createDoublesKnockoutCompetition($context);
        $context->createBracket($competition)->assertCreated();
        $tournament = $competition->tournament;

        $context->closeTournament($tournament)->assertUnprocessable();
    }

    public function test_close_tournament_audit_includes_doubles_entry_fields(): void
    {
        $context = $this->tournamentContext();
        $competition = $this->createDoublesKnockoutCompetition($context);
        $result = $context->completeDoublesCompetitionThroughFinal($competition);
        $tournament = $competition->tournament;

        Activity::query()->delete();

        $context->closeTournament($tournament)->assertOk();

        $activity = Activity::query()->where('description', 'tournament.closed')->sole();

        $this->assertSame(
            $result['champion_entry']->id,
            data_get($activity->properties, 'summary.results.0.champion_entry_id'),
        );
        $this->assertNull(data_get($activity->properties, 'summary.results.0.champion_id'));
        $this->assertSame(
            CompetitionEntryDisplayName::for($result['champion_entry']),
            data_get($activity->properties, 'summary.results.0.champion_display_name'),
        );
    }

    private function createDoublesKnockoutCompetition($context, ?ThirdPlaceMode $thirdPlaceMode = null)
    {
        $competition = $context->createDoublesKnockoutDirectCompetition();

        if ($thirdPlaceMode !== null) {
            $competition->update(['third_place_mode' => $thirdPlaceMode]);
        }

        $players = $context->createPlayers(8);
        $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
            [$players[6], $players[7]],
        ]);

        return $competition;
    }
}
