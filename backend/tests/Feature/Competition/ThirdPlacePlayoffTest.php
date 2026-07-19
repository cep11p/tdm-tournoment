<?php

namespace Tests\Feature\Competition;

use App\Enums\AuditAction;
use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use App\Enums\ThirdPlaceMode;
use App\Models\Bracket;
use App\Models\Game;
use App\Models\Player;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ThirdPlacePlayoffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    /**
     * @return array{
     *     context: \Tests\Support\TournamentTestContext,
     *     competition: \App\Models\Competition,
     *     bracket: Bracket,
     *     players: array<int, Player>,
     *     semifinals: \Illuminate\Support\Collection<int, Game>,
     * }
     */
    private function setupPlayoffKnockoutDirect(int $playerCount = 4): array
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Playoff]);
        $competition->refresh();

        $players = $context->createPlayers($playerCount);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        return compact('context', 'competition', 'bracket', 'players', 'semifinals');
    }

    private function finishSemifinalsAndAdvance(array $setup): Game
    {
        $context = $setup['context'];
        $bracket = $setup['bracket'];
        $players = $setup['players'];

        $context->finishGame($setup['semifinals'][0], $players[0])->assertOk();
        $context->finishGame($setup['semifinals'][1], $players[2])->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        return $context->bracketGamesForRound($bracket->fresh(), 2)->sole();
    }

    public function test_playoff_with_four_players_creates_final_and_third_place(): void
    {
        $setup = $this->setupPlayoffKnockoutDirect();
        $competition = $setup['competition'];
        $competition->update(['semifinal_best_of' => 3]);
        $competition->refresh();

        $final = $this->finishSemifinalsAndAdvance($setup);

        $thirdPlace = Game::query()
            ->where('bracket_id', $setup['bracket']->id)
            ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
            ->sole();

        $losers = \App\Support\Bracket\BracketPodiumSupport::thirdPlaceParticipants($setup['bracket']->fresh());

        $this->assertSame('Final', $final->round);
        $this->assertSame(BracketGamePurpose::Main, $final->bracket_purpose);
        $this->assertSame('Tercer puesto', $thirdPlace->round);
        $this->assertNull($thirdPlace->bracket_round);
        $this->assertSame(1, $thirdPlace->bracket_match);
        $this->assertSame(GameStatus::Pending, $thirdPlace->status);
        $this->assertSame($losers[0]->id, $thirdPlace->player1_id);
        $this->assertSame($losers[1]->id, $thirdPlace->player2_id);
        $this->assertSame(3, $thirdPlace->best_of);
        $this->assertSame(2, $thirdPlace->sets_to_win);
        $this->assertFalse((bool) $thirdPlace->is_bye);
    }

    public function test_playoff_advance_is_idempotent_for_third_place(): void
    {
        $setup = $this->setupPlayoffKnockoutDirect();
        $this->finishSemifinalsAndAdvance($setup);

        $countBefore = Game::query()
            ->where('bracket_id', $setup['bracket']->id)
            ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
            ->count();

        $setup['context']->generateBracketNextRound($setup['bracket']->fresh())
            ->assertUnprocessable();

        $this->assertSame(
            $countBefore,
            Game::query()
                ->where('bracket_id', $setup['bracket']->id)
                ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
                ->count(),
        );
    }

    public function test_none_and_shared_do_not_create_third_place_game(): void
    {
        foreach ([ThirdPlaceMode::None, ThirdPlaceMode::Shared] as $mode) {
            $context = $this->tournamentContext();
            $competition = $context->createKnockoutDirectCompetition();
            $competition->update(['third_place_mode' => $mode]);
            $competition->refresh();

            $players = $context->createPlayers(4);
            $context->registerPlayers($competition, $players);
            $context->createBracket($competition)->assertCreated();

            $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
            $semifinals = $context->bracketGamesForRound($bracket, 1);
            $context->finishGame($semifinals[0], $players[0])->assertOk();
            $context->finishGame($semifinals[1], $players[2])->assertOk();
            $context->generateBracketNextRound($bracket)->assertCreated();

            $this->assertSame(
                0,
                Game::query()
                    ->where('bracket_id', $bracket->id)
                    ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
                    ->count(),
                sprintf('Mode %s should not create third place game.', $mode->value),
            );
        }
    }

    public function test_playoff_status_combinations_for_eligible_bracket(): void
    {
        $setup = $this->setupPlayoffKnockoutDirect();
        $context = $setup['context'];
        $competition = $setup['competition'];
        $players = $setup['players'];
        $final = $this->finishSemifinalsAndAdvance($setup);

        $thirdPlace = Game::query()
            ->where('bracket_id', $setup['bracket']->id)
            ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
            ->sole();

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'knockout_in_progress');

        $context->finishGame($final, $players[0])->assertOk();

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'knockout_in_progress')
            ->assertJsonPath('data.status_summary.next_action', 'Completar partido por tercer puesto');

        $setupTwo = $this->setupPlayoffKnockoutDirect();
        $finalTwo = $this->finishSemifinalsAndAdvance($setupTwo);
        $thirdPlaceTwo = Game::query()
            ->where('bracket_id', $setupTwo['bracket']->id)
            ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
            ->sole();

        $context->finishGame($thirdPlaceTwo, $setupTwo['players'][1])->assertOk();

        $this->getJson($context->apiUrl("competitions/{$setupTwo['competition']->id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'knockout_in_progress')
            ->assertJsonPath('data.status_summary.next_action', 'Completar final');

        $context->finishGame($finalTwo, $setupTwo['players'][0])->assertOk();

        $this->getJson($context->apiUrl("competitions/{$setupTwo['competition']->id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'completed');

        $context->finishGame($thirdPlace->fresh(), $players[1])->assertOk();

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'completed');
    }

    public function test_playoff_with_two_players_completes_without_third_place(): void
    {
        $setup = $this->setupPlayoffKnockoutDirect(2);
        $context = $setup['context'];
        $competition = $setup['competition'];
        $players = $setup['players'];

        $final = $context->bracketGamesForRound($setup['bracket'], 1)->sole();
        $context->finishGame($final, $players[0])->assertOk();

        $this->assertSame(
            0,
            Game::query()
                ->where('bracket_id', $setup['bracket']->id)
                ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
                ->count(),
        );

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'completed');
    }

    public function test_playoff_with_three_players_completes_without_third_place(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Playoff]);
        $competition->refresh();

        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $playIn = $context->bracketGamesForRound($bracket, 1)
            ->first(fn (Game $game): bool => ! $game->is_bye);
        $this->assertNotNull($playIn);
        $context->finishGame($playIn, $playIn->player1)->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        while (! Game::query()
            ->where('bracket_id', $bracket->id)
            ->mainBracket()
            ->where('round', 'Final')
            ->exists()) {
            $currentRound = (int) Game::query()
                ->where('bracket_id', $bracket->id)
                ->mainBracket()
                ->max('bracket_round');

            foreach ($context->bracketGamesForRound($bracket->fresh(), $currentRound) as $game) {
                if (! $game->is_bye && $game->status !== GameStatus::Finished) {
                    $context->finishGame($game, $game->player1)->assertOk();
                }
            }

            if (Game::query()
                ->where('bracket_id', $bracket->id)
                ->mainBracket()
                ->where('round', 'Final')
                ->exists()) {
                break;
            }

            $context->generateBracketNextRound($bracket->fresh())->assertCreated();
        }

        $final = Game::query()
            ->where('bracket_id', $bracket->id)
            ->mainBracket()
            ->where('round', 'Final')
            ->sole();
        $context->finishGame($final, $final->player1)->assertOk();

        $this->assertSame(
            0,
            Game::query()
                ->where('bracket_id', $bracket->id)
                ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
                ->count(),
        );

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'completed');
    }

    public function test_playoff_result_summary_pending_and_finished(): void
    {
        $setup = $this->setupPlayoffKnockoutDirect();
        $context = $setup['context'];
        $competition = $setup['competition'];
        $players = $setup['players'];
        $final = $this->finishSemifinalsAndAdvance($setup);

        $thirdPlace = Game::query()
            ->where('bracket_id', $setup['bracket']->id)
            ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
            ->sole();

        $context->finishGame($final, $players[0])->assertOk();

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonPath('data.result_summary.third_place_mode', ThirdPlaceMode::Playoff->value)
            ->assertJsonPath('data.result_summary.third_place', [])
            ->assertJsonPath('data.result_summary.fourth_place', null)
            ->assertJsonPath('data.result_summary.third_place_game_id', $thirdPlace->id);

        $context->finishGame($thirdPlace->fresh(), $players[1])->assertOk();

        $this->getJson($context->apiUrl("competitions/{$competition->id}"))
            ->assertOk()
            ->assertJsonCount(1, 'data.result_summary.third_place')
            ->assertJsonPath('data.result_summary.third_place.0.id', $players[1]->id)
            ->assertJsonPath('data.result_summary.fourth_place.id', $players[3]->id)
            ->assertJsonPath('data.result_summary.third_place_game_id', $thirdPlace->id);
    }

    public function test_playoff_closure_blocked_until_third_place_finishes(): void
    {
        $setup = $this->setupPlayoffKnockoutDirect();
        $context = $setup['context'];
        $competition = $setup['competition'];
        $players = $setup['players'];
        $final = $this->finishSemifinalsAndAdvance($setup);

        $context->finishGame($final, $players[0])->assertOk();

        $context->closeTournament($competition->tournament)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);

        $thirdPlace = Game::query()
            ->where('bracket_id', $setup['bracket']->id)
            ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
            ->sole();

        $context->finishGame($thirdPlace, $players[1])->assertOk();

        $context->closeTournament($competition->tournament)
            ->assertOk()
            ->assertJsonPath('data.results_summary.results.0.third_place_mode', ThirdPlaceMode::Playoff->value)
            ->assertJsonPath('data.results_summary.results.0.third_place.0.id', $players[1]->id)
            ->assertJsonPath('data.results_summary.results.0.fourth_place.id', $players[3]->id);
    }

    public function test_playoff_closure_message_mentions_pending_third_place(): void
    {
        $setup = $this->setupPlayoffKnockoutDirect();
        $context = $setup['context'];
        $competition = $setup['competition'];
        $final = $this->finishSemifinalsAndAdvance($setup);

        $context->finishGame($final, $setup['players'][0])->assertOk();

        $response = $context->closeTournament($competition->tournament);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tournament']);

        $this->assertStringContainsString(
            'partido por tercer puesto',
            (string) $response->json('errors.tournament.0'),
        );
    }

    public function test_rejects_semifinal_correction_when_third_place_exists(): void
    {
        $setup = $this->setupPlayoffKnockoutDirect();
        $context = $setup['context'];
        $this->finishSemifinalsAndAdvance($setup);

        $semifinal = $setup['semifinals'][0]->fresh(['player1', 'player2', 'competition', 'sets']);
        $newWinner = (int) $semifinal->winner_id === (int) $semifinal->player1_id
            ? $semifinal->player2
            : $semifinal->player1;

        $pointsPerSet = (int) $semifinal->competition->points_per_set;
        $setsToWin = (int) $semifinal->sets_to_win;
        $sets = [];

        for ($setNumber = 1; $setNumber <= $setsToWin; $setNumber++) {
            $sets[] = [
                'player1_score' => (int) $semifinal->player1_id === $newWinner->id ? $pointsPerSet : 0,
                'player2_score' => (int) $semifinal->player2_id === $newWinner->id ? $pointsPerSet : 0,
            ];
        }

        $context->correctResult(
            $semifinal,
            'Corrección temporal bloqueada',
            $sets,
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['game']);
    }

    public function test_bracket_round_advanced_audit_includes_third_place_creation(): void
    {
        $setup = $this->setupPlayoffKnockoutDirect();

        Activity::query()->delete();

        $this->finishSemifinalsAndAdvance($setup);

        $activity = Activity::query()
            ->where('description', AuditAction::BRACKET_ROUND_ADVANCED->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertTrue((bool) data_get($activity->properties, 'summary.third_place_game_created'));
        $this->assertNotNull(data_get($activity->properties, 'summary.third_place_game_id'));
        $this->assertSame(0, Activity::query()->where('description', AuditAction::GAME_CREATED->value)->count());
    }

    public function test_third_place_game_records_sets_via_standard_flow(): void
    {
        $setup = $this->setupPlayoffKnockoutDirect();
        $context = $setup['context'];
        $this->finishSemifinalsAndAdvance($setup);

        $thirdPlace = Game::query()
            ->where('bracket_id', $setup['bracket']->id)
            ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
            ->sole();

        Activity::query()->delete();

        $context->finishGame($thirdPlace, $setup['players'][1])->assertOk();

        $this->assertSame(GameStatus::Finished, $thirdPlace->fresh()->status);
        $this->assertSame(
            1,
            Activity::query()->where('description', AuditAction::GAME_SET_RECORDED->value)->count(),
        );
    }

    public function test_rejects_manual_delete_of_bracket_third_place_game(): void
    {
        $setup = $this->setupPlayoffKnockoutDirect();
        $this->finishSemifinalsAndAdvance($setup);

        $thirdPlace = Game::query()
            ->where('bracket_id', $setup['bracket']->id)
            ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
            ->sole();

        $setup['context']->deleteGame($thirdPlace)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['game']);
    }

    public function test_playoff_with_groups_knockout_creates_third_place(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $setup['competition']->update(['third_place_mode' => ThirdPlaceMode::Playoff]);
        $setup['competition']->refresh();

        $context->createBracket($setup['competition'])->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();
        $context->finishGame($semifinals[1], $setup['playerThree'])->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $this->assertSame(
            1,
            Game::query()
                ->where('bracket_id', $bracket->id)
                ->where('bracket_purpose', BracketGamePurpose::ThirdPlace)
                ->count(),
        );
    }
}
