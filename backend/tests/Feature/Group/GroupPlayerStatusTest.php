<?php

namespace Tests\Feature\Group;

use App\Enums\GameStatus;
use App\Enums\GroupPlayerStatus;
use App\Models\Bracket;
use App\Models\Game;
use App\Models\GroupPlayer;
use App\Models\Player;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class GroupPlayerStatusTest extends TestCase
{
    public function test_marks_player_as_withdrawn_and_persists_metadata(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createGroupWithRoundRobin($context, playerCount: 3);
        $group = $setup['group'];
        $player = $setup['players'][0];

        $response = $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $player->id,
            'status' => 'withdrawn',
            'reason' => 'no_show',
            'notes' => 'No se presentó el sábado',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.player_id', $player->id)
            ->assertJsonPath('data.status', 'withdrawn')
            ->assertJsonPath('data.status_reason', 'no_show')
            ->assertJsonPath('data.status_notes', 'No se presentó el sábado');

        $this->assertDatabaseHas('group_players', [
            'group_id' => $group->id,
            'player_id' => $player->id,
            'status' => GroupPlayerStatus::Withdrawn->value,
            'status_reason' => 'no_show',
            'status_notes' => 'No se presentó el sábado',
        ]);

        $this->assertNotNull(
            GroupPlayer::query()
                ->where('group_id', $group->id)
                ->where('player_id', $player->id)
                ->value('status_changed_at')
        );
    }

    public function test_marks_player_as_disqualified_and_persists_metadata(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createGroupWithRoundRobin($context, playerCount: 3);
        $group = $setup['group'];
        $player = $setup['players'][1];

        $response = $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $player->id,
            'status' => 'disqualified',
            'reason' => 'organizer_decision',
            'notes' => 'Conducta antideportiva',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'disqualified')
            ->assertJsonPath('data.status_reason', 'organizer_decision')
            ->assertJsonPath('data.status_notes', 'Conducta antideportiva');

        $this->assertDatabaseHas('group_players', [
            'group_id' => $group->id,
            'player_id' => $player->id,
            'status' => GroupPlayerStatus::Disqualified->value,
            'status_reason' => 'organizer_decision',
        ]);
    }

    public function test_closes_pending_games_for_withdrawn_player_in_favor_of_opponent(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createGroupWithRoundRobin($context, playerCount: 3);
        $group = $setup['group'];
        [$playerOne, $playerTwo, $playerThree] = $setup['players'];
        $games = Game::query()->where('group_id', $group->id)->get();

        $finishedGame = $context->findGameBetween($games, $playerOne, $playerTwo);
        $context->finishGame($finishedGame, $playerOne)->assertOk();

        $pendingGame = $context->findGameBetween($games, $playerOne, $playerThree);
        $this->assertSame(GameStatus::Pending, $pendingGame->fresh()->status);

        $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $playerOne->id,
            'status' => 'withdrawn',
            'reason' => 'injury',
        ])->assertCreated();

        $pendingGame->refresh();

        $this->assertSame(GameStatus::Finished, $pendingGame->status);
        $this->assertSame($playerThree->id, $pendingGame->winner_id);
        $this->assertCount(0, $pendingGame->sets);
    }

    public function test_does_not_modify_already_finished_games(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createGroupWithRoundRobin($context, playerCount: 3);
        $group = $setup['group'];
        [$playerOne, $playerTwo] = $setup['players'];
        $games = Game::query()->where('group_id', $group->id)->get();

        $finishedGame = $context->findGameBetween($games, $playerOne, $playerTwo);
        $context->finishGame($finishedGame, $playerOne)->assertOk();
        $finishedGame->refresh();

        $originalWinnerId = $finishedGame->winner_id;
        $originalFinishedAt = $finishedGame->finished_at?->toIso8601String();
        $originalSetCount = $finishedGame->sets()->count();

        $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $playerOne->id,
            'status' => 'withdrawn',
        ])->assertCreated();

        $finishedGame->refresh();

        $this->assertSame($originalWinnerId, $finishedGame->winner_id);
        $this->assertSame($originalFinishedAt, $finishedGame->finished_at?->toIso8601String());
        $this->assertSame($originalSetCount, $finishedGame->sets()->count());
    }

    public function test_standings_shows_withdrawn_player_at_the_bottom(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createGroupWithRoundRobin($context, playerCount: 3);
        $group = $setup['group'];
        [$playerOne, $playerTwo, $playerThree] = $setup['players'];
        $games = Game::query()->where('group_id', $group->id)->get();

        $context->finishGame($context->findGameBetween($games, $playerOne, $playerTwo), $playerOne)->assertOk();
        $context->finishGame($context->findGameBetween($games, $playerOne, $playerThree), $playerOne)->assertOk();
        $context->finishGame($context->findGameBetween($games, $playerTwo, $playerThree), $playerTwo)->assertOk();

        $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $playerOne->id,
            'status' => 'withdrawn',
        ])->assertCreated();

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('data.0.player_id', $playerTwo->id)
            ->assertJsonPath('data.1.player_id', $playerThree->id)
            ->assertJsonPath('data.2.player_id', $playerOne->id)
            ->assertJsonPath('data.2.won', 2)
            ->assertJsonPath('data.2.lost', 0);
    }

    public function test_standings_marks_withdrawn_player_as_not_eligible_for_qualification(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createGroupWithRoundRobin($context, playerCount: 3);
        $group = $setup['group'];
        $player = $setup['players'][0];

        $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $player->id,
            'status' => 'withdrawn',
        ])->assertCreated();

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('data.2.player_id', $player->id)
            ->assertJsonPath('data.2.eligible_for_qualification', false)
            ->assertJsonPath('data.2.group_player_status', 'withdrawn')
            ->assertJsonPath('data.0.eligible_for_qualification', true)
            ->assertJsonPath('data.0.group_player_status', 'active');
    }

    public function test_bracket_excludes_withdrawn_player(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(5);
        $context->registerPlayers($competition, $players);
        [$playerOne, $playerTwo, $playerThree, $playerFour, $playerFive] = $players;

        $groupA = $context->createGroupWithPlayers($competition, [$playerOne, $playerTwo, $playerThree], 'Grupo A');
        $groupB = $context->createGroupWithPlayers($competition, [$playerFour, $playerFive], 'Grupo B');

        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();

        $groupAGames = Game::query()->where('group_id', $groupA->id)->get();
        $context->finishGame($context->findGameBetween($groupAGames, $playerOne, $playerTwo), $playerOne)->assertOk();
        $context->finishGame($context->findGameBetween($groupAGames, $playerOne, $playerThree), $playerOne)->assertOk();
        $context->finishGame($context->findGameBetween($groupAGames, $playerTwo, $playerThree), $playerTwo)->assertOk();

        $groupBGame = Game::query()->where('group_id', $groupB->id)->sole();
        $context->finishGame($groupBGame, $playerFour)->assertOk();

        $this->postJson($context->apiUrl("groups/{$groupA->id}/player-status"), [
            'player_id' => $playerTwo->id,
            'status' => 'withdrawn',
        ])->assertCreated();

        $competition->update(['qualified_per_group' => 2]);
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $qualifierIds = Game::query()
            ->where('bracket_id', $bracket->id)
            ->get()
            ->flatMap(fn (Game $game): array => [(int) $game->player1_id, (int) $game->player2_id])
            ->filter(fn (int $playerId): bool => $playerId > 0)
            ->unique()
            ->values()
            ->all();

        $this->assertContains($playerOne->id, $qualifierIds);
        $this->assertContains($playerThree->id, $qualifierIds);
        $this->assertContains($playerFour->id, $qualifierIds);
        $this->assertContains($playerFive->id, $qualifierIds);
        $this->assertNotContains($playerTwo->id, $qualifierIds);
    }

    public function test_bracket_can_be_created_after_withdrawal_because_pending_games_are_closed(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(5);
        $context->registerPlayers($competition, $players);
        [$playerOne, $playerTwo, $playerThree, $playerFour, $playerFive] = $players;

        $groupA = $context->createGroupWithPlayers($competition, [$playerOne, $playerTwo, $playerThree], 'Grupo A');
        $groupB = $context->createGroupWithPlayers($competition, [$playerFour, $playerFive], 'Grupo B');

        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();

        $groupAGames = Game::query()->where('group_id', $groupA->id)->get();
        $context->finishGame($context->findGameBetween($groupAGames, $playerTwo, $playerThree), $playerTwo)->assertOk();

        $pendingGame = $context->findGameBetween($groupAGames, $playerOne, $playerTwo);
        $this->assertSame(GameStatus::Pending, $pendingGame->fresh()->status);

        $groupBGame = Game::query()->where('group_id', $groupB->id)->sole();
        $context->finishGame($groupBGame, $playerFour)->assertOk();

        $this->postJson($context->apiUrl("groups/{$groupA->id}/player-status"), [
            'player_id' => $playerOne->id,
            'status' => 'withdrawn',
        ])->assertCreated();

        $competition->update(['qualified_per_group' => 2]);
        $response = $context->createBracket($competition);

        $response->assertCreated();
        $this->assertSame(
            0,
            Game::query()
                ->where('group_id', $groupA->id)
                ->where('status', '!=', GameStatus::Finished)
                ->count()
        );
    }

    public function test_rejects_player_not_in_group(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createGroupWithRoundRobin($context, playerCount: 3);
        $group = $setup['group'];
        $outsidePlayer = Player::query()->create([
            'first_name' => 'Externo',
            'last_name' => 'Test',
        ]);

        $response = $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $outsidePlayer->id,
            'status' => 'withdrawn',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);
    }

    public function test_rejects_attempt_to_set_active_status(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createGroupWithRoundRobin($context, playerCount: 3);
        $group = $setup['group'];
        $player = $setup['players'][0];

        $response = $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $player->id,
            'status' => 'active',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_rejects_changing_status_when_player_is_no_longer_active(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createGroupWithRoundRobin($context, playerCount: 3);
        $group = $setup['group'];
        $player = $setup['players'][0];
        $url = $context->apiUrl("groups/{$group->id}/player-status");

        $this->postJson($url, [
            'player_id' => $player->id,
            'status' => 'withdrawn',
        ])->assertCreated();

        $response = $this->postJson($url, [
            'player_id' => $player->id,
            'status' => 'disqualified',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_id']);
    }

    public function test_rejects_status_change_when_bracket_already_exists(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $group = $setup['groupA'];
        $player = $setup['playerTwo'];

        $context->createBracket($setup['competition'])->assertCreated();

        $response = $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $player->id,
            'status' => 'withdrawn',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group']);
    }

    public function test_manual_tiebreak_override_becomes_stale_after_withdrawal_changes_standings_context(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedTripleTie($context);
        $group = $setup['group'];
        [$playerA, $playerB, $playerC] = $setup['players'];

        $this->postJson($context->apiUrl("groups/{$group->id}/manual-tiebreaks"), [
            'player_ids' => [$playerB->id, $playerA->id, $playerC->id],
            'reason' => 'draw',
        ])->assertCreated();

        $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $playerA->id,
            'status' => 'withdrawn',
        ])->assertCreated();

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('data.2.player_id', $playerA->id)
            ->assertJsonPath('data.2.group_player_status', 'withdrawn')
            ->assertJsonCount(1, 'meta.stale_manual_tiebreaks')
            ->assertJsonPath('meta.stale_manual_tiebreaks.0.player_ids', [$playerB->id, $playerA->id, $playerC->id]);
    }

    /**
     * @return array{
     *     competition: \App\Models\Competition,
     *     group: \App\Models\Group,
     *     players: array<int, Player>
     * }
     */
    private function createGroupWithRoundRobin(TournamentTestContext $context, int $playerCount): array
    {
        $competition = $context->createCompetition();
        $players = $context->createPlayers($playerCount);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        return [
            'competition' => $competition,
            'group' => $group,
            'players' => $players,
        ];
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
