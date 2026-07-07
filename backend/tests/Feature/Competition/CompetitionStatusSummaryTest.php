<?php

namespace Tests\Feature\Competition;

use App\Models\Game;
use App\Models\Player;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class CompetitionStatusSummaryTest extends TestCase
{
    public function test_competition_without_groups_returns_no_groups_status(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'no_groups')
            ->assertJsonPath('data.status_summary.label', 'Sin grupos');
    }

    public function test_competition_with_groups_but_no_group_games_returns_group_stage_pending(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createGroupWithPlayers($competition, $players);

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'group_stage_pending');
    }

    public function test_competition_with_pending_group_games_returns_group_stage_in_progress(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: false);

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'group_stage_in_progress');
    }

    public function test_competition_with_pending_manual_tiebreak_at_cutoff_returns_attention_required(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedTripleTie($context);
        $competition = $setup['competition'];

        $competition->update(['qualified_per_group' => 2]);
        $competition->refresh();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'group_stage_attention_required')
            ->assertJsonPath('data.status_summary.label', 'Fase de grupos requiere atención')
            ->assertJsonPath('data.status_summary.next_action', 'Resolver desempates de grupos');

        $this->assertNotSame('ready_for_bracket', $response->json('data.status_summary.code'));
    }

    public function test_competition_with_resolved_manual_tiebreak_returns_ready_for_bracket(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedTripleTie($context);
        $group = $setup['group'];
        $competition = $setup['competition'];
        [$playerA, $playerB, $playerC] = $setup['players'];

        $competition->update(['qualified_per_group' => 2]);
        $competition->refresh();

        $this->postJson($context->apiUrl("groups/{$group->id}/manual-tiebreaks"), [
            'player_ids' => [$playerB->id, $playerA->id, $playerC->id],
            'reason' => 'draw',
        ])->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'ready_for_bracket')
            ->assertJsonPath('data.status_summary.next_action', 'Generar llave eliminatoria');
    }

    public function test_competition_with_finished_groups_and_no_bracket_returns_ready_for_bracket(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase(finishGroupGames: true);

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'ready_for_bracket')
            ->assertJsonPath('data.status_summary.next_action', 'Generar llave eliminatoria');
    }

    public function test_competition_with_bracket_and_pending_games_returns_knockout_in_progress(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'knockout_in_progress');
    }

    public function test_competition_with_finished_final_returns_completed(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = $setup['competition']->fresh()->brackets()->firstOrFail();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        foreach ($semifinals as $game) {
            if (! $game->is_bye) {
                $context->finishGame($game, $game->player1)->assertOk();
            }
        }

        $context->generateBracketNextRound($bracket)->assertCreated();

        $final = $context->bracketGamesForRound($bracket->fresh(), 2)->sole();
        $context->finishGame($final, $final->player1)->assertOk();

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'completed')
            ->assertJsonPath('data.status_summary.next_action', 'Ver llave');
    }

    public function test_knockout_in_progress_suggests_generate_next_round_when_current_round_is_complete(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = $setup['competition']->fresh()->brackets()->firstOrFail();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        foreach ($semifinals as $game) {
            if (! $game->is_bye) {
                $context->finishGame($game, $game->player1)->assertOk();
            }
        }

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'knockout_in_progress')
            ->assertJsonPath('data.status_summary.next_action', 'Generar siguiente ronda');

        $this->assertFalse(
            Game::query()
                ->where('competition_id', $setup['competition']->id)
                ->where('round', 'Final')
                ->exists()
        );
    }

    public function test_knockout_direct_with_insufficient_registrations_returns_awaiting_registrations(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(1);
        $context->registerPlayers($competition, $players);

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'awaiting_registrations')
            ->assertJsonPath('data.status_summary.label', 'Esperando inscriptos')
            ->assertJsonPath('data.has_group_stage', false);
    }

    public function test_knockout_direct_with_enough_registrations_returns_ready_for_bracket(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'ready_for_bracket')
            ->assertJsonPath('data.status_summary.label', 'Lista para generar llave');
    }

    public function test_knockout_direct_with_bracket_returns_knockout_in_progress(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response
            ->assertOk()
            ->assertJsonPath('data.status_summary.code', 'knockout_in_progress');

        $this->assertNotSame('no_groups', $response->json('data.status_summary.code'));
    }

    public function test_knockout_direct_never_returns_no_groups(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}"));

        $response->assertOk();

        $this->assertNotSame('no_groups', $response->json('data.status_summary.code'));
    }

    /**
     * @return array{
     *     competition: \App\Models\Competition,
     *     group: \App\Models\Group,
     *     players: array<int, Player>
     * }
     */
    private function createUnresolvedTripleTie(TournamentTestContext $context): array
    {
        $competition = $context->createCompetition(setsToWin: 3);
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()->where('group_id', $group->id)->get();
        $balancedSets = [
            [11, 9],
            [11, 9],
            [9, 11],
            [11, 9],
        ];

        $this->playMatch($context, $context->findGameBetween($games, $players[0], $players[1]), $players[0], $players[1], $balancedSets);
        $this->playMatch($context, $context->findGameBetween($games, $players[1], $players[2]), $players[1], $players[2], $balancedSets);
        $this->playMatch($context, $context->findGameBetween($games, $players[2], $players[0]), $players[2], $players[0], $balancedSets);

        return [
            'competition' => $competition,
            'group' => $group,
            'players' => $players,
        ];
    }

    /**
     * @param  array<int, array{int, int}>  $sets
     */
    private function playMatch(
        TournamentTestContext $context,
        Game $game,
        Player $leftPlayer,
        Player $rightPlayer,
        array $sets,
    ): void {
        foreach ($sets as $index => [$leftScore, $rightScore]) {
            $player1IsLeft = (int) $game->player1_id === $leftPlayer->id;
            $player1Score = $player1IsLeft ? $leftScore : $rightScore;
            $player2Score = $player1IsLeft ? $rightScore : $leftScore;

            $context->recordSet(
                $game,
                setNumber: $index + 1,
                player1Score: $player1Score,
                player2Score: $player2Score,
            )->assertOk();
        }
    }
}
