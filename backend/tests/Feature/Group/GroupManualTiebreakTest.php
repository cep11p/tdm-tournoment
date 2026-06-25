<?php

namespace Tests\Feature\Group;

use App\Models\Game;
use App\Models\GroupManualTiebreak;
use App\Models\GroupManualTiebreakPlayer;
use App\Models\Player;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class GroupManualTiebreakTest extends TestCase
{
    public function test_applies_manual_tiebreak_for_pending_group(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedTripleTie($context);
        $group = $setup['group'];
        [$playerA, $playerB, $playerC] = $setup['players'];

        $response = $this->postJson($context->apiUrl("groups/{$group->id}/manual-tiebreaks"), [
            'player_ids' => [$playerB->id, $playerA->id, $playerC->id],
            'reason' => 'draw',
            'notes' => 'Sorteo entre empatados',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.player_ids', [$playerB->id, $playerA->id, $playerC->id])
            ->assertJsonPath('data.reason', 'draw')
            ->assertJsonPath('data.notes', 'Sorteo entre empatados');

        $standings = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $standings
            ->assertOk()
            ->assertJsonPath('data.0.player_id', $playerB->id)
            ->assertJsonPath('data.1.player_id', $playerA->id)
            ->assertJsonPath('data.2.player_id', $playerC->id)
            ->assertJsonPath('data.0.manual_tiebreak_applied', true)
            ->assertJsonPath('data.0.manual_position', 1)
            ->assertJsonPath('data.1.manual_position', 2)
            ->assertJsonPath('data.2.manual_position', 3)
            ->assertJsonPath('meta.requires_manual_tiebreak', false)
            ->assertJsonPath('meta.has_manual_tiebreaks', true)
            ->assertJsonCount(1, 'meta.manual_tiebreaks');
    }

    public function test_bracket_can_be_created_after_manual_resolution(): void
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

        $context->createBracket($competition)->assertCreated();
    }

    public function test_rejects_players_not_in_group(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedTripleTie($context);
        $group = $setup['group'];
        $outsider = $context->createPlayers(1)[0];

        $response = $this->postJson($context->apiUrl("groups/{$group->id}/manual-tiebreaks"), [
            'player_ids' => [$setup['players'][0]->id, $setup['players'][1]->id, $outsider->id],
            'reason' => 'draw',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids']);
    }

    public function test_rejects_duplicate_player_ids(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedTripleTie($context);
        $group = $setup['group'];
        [$playerA] = $setup['players'];

        $response = $this->postJson($context->apiUrl("groups/{$group->id}/manual-tiebreaks"), [
            'player_ids' => [$playerA->id, $playerA->id, $playerA->id],
            'reason' => 'draw',
        ]);

        $response->assertUnprocessable();
    }

    public function test_rejects_incomplete_player_set(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedTripleTie($context);
        $group = $setup['group'];
        [$playerA, $playerB] = $setup['players'];

        $response = $this->postJson($context->apiUrl("groups/{$group->id}/manual-tiebreaks"), [
            'player_ids' => [$playerA->id, $playerB->id],
            'reason' => 'draw',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids']);
    }

    public function test_rejects_when_no_pending_tie_exists(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $games = Game::query()->where('group_id', $group->id)->get();

        $this->playMatch($context, $context->findGameBetween($games, $players[0], $players[1]), $players[0], $players[1], [[11, 8]]);
        $this->playMatch($context, $context->findGameBetween($games, $players[0], $players[2]), $players[0], $players[2], [[11, 7]]);
        $this->playMatch($context, $context->findGameBetween($games, $players[1], $players[2]), $players[1], $players[2], [[11, 9]]);

        $response = $this->postJson($context->apiUrl("groups/{$group->id}/manual-tiebreaks"), [
            'player_ids' => [$players[0]->id, $players[1]->id, $players[2]->id],
            'reason' => 'draw',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_ids']);
    }

    public function test_rejects_when_bracket_already_exists(): void
    {
        $context = $this->tournamentContext();
        $fourGroupSetup = $context->createFourQualifierGroupPhase();
        $context->createBracket($fourGroupSetup['competition'])->assertCreated();

        $response = $this->postJson($context->apiUrl("groups/{$fourGroupSetup['groupA']->id}/manual-tiebreaks"), [
            'player_ids' => [$fourGroupSetup['playerOne']->id, $fourGroupSetup['playerTwo']->id],
            'reason' => 'draw',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group']);
    }

    public function test_allows_upsert_for_same_player_set(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedTripleTie($context);
        $group = $setup['group'];
        [$playerA, $playerB, $playerC] = $setup['players'];

        $this->postJson($context->apiUrl("groups/{$group->id}/manual-tiebreaks"), [
            'player_ids' => [$playerB->id, $playerA->id, $playerC->id],
            'reason' => 'draw',
        ])->assertCreated();

        $response = $this->postJson($context->apiUrl("groups/{$group->id}/manual-tiebreaks"), [
            'player_ids' => [$playerC->id, $playerB->id, $playerA->id],
            'reason' => 'organizer_decision',
            'notes' => 'Revisado',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.reason', 'organizer_decision')
            ->assertJsonPath('data.notes', 'Revisado');

        $this->assertSame(1, GroupManualTiebreak::query()->where('group_id', $group->id)->count());

        $standings = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $standings
            ->assertOk()
            ->assertJsonPath('data.0.player_id', $playerC->id)
            ->assertJsonPath('data.1.player_id', $playerB->id)
            ->assertJsonPath('data.2.player_id', $playerA->id);
    }

    public function test_marks_stale_override_when_it_no_longer_matches_pending_tie(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedTripleTie($context);
        $group = $setup['group'];
        [$playerA, $playerB, $playerC] = $setup['players'];

        $staleTiebreak = GroupManualTiebreak::query()->create([
            'group_id' => $group->id,
            'reason' => 'draw',
            'notes' => 'Override viejo',
            'applied_at' => now(),
        ]);

        GroupManualTiebreakPlayer::query()->create([
            'group_manual_tiebreak_id' => $staleTiebreak->id,
            'player_id' => $playerA->id,
            'position' => 1,
        ]);

        GroupManualTiebreakPlayer::query()->create([
            'group_manual_tiebreak_id' => $staleTiebreak->id,
            'player_id' => $playerB->id,
            'position' => 2,
        ]);

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response
            ->assertOk()
            ->assertJsonPath('meta.requires_manual_tiebreak', true)
            ->assertJsonCount(1, 'meta.stale_manual_tiebreaks')
            ->assertJsonPath('meta.stale_manual_tiebreaks.0.player_ids', [$playerA->id, $playerB->id]);
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
