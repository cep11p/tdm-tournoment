<?php

namespace Tests\Unit\Bracket;

use App\Data\Competition\GroupQualifierData;
use App\Models\Game;
use App\Models\Player;
use App\Support\Bracket\GroupQualifiersCollector;
use Illuminate\Validation\ValidationException;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class GroupQualifiersCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_collects_qualifiers_with_group_metadata_for_two_groups(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $qualifiers = app(GroupQualifiersCollector::class)->collect($setup['competition']->fresh());

        $this->assertCount(4, $qualifiers);

        $groupAQualifiers = $qualifiers
            ->filter(fn (GroupQualifierData $qualifier): bool => $qualifier->groupId === $setup['groupA']->id)
            ->sortBy('groupPosition')
            ->values();

        $groupBQualifiers = $qualifiers
            ->filter(fn (GroupQualifierData $qualifier): bool => $qualifier->groupId === $setup['groupB']->id)
            ->sortBy('groupPosition')
            ->values();

        $this->assertCount(2, $groupAQualifiers);
        $this->assertCount(2, $groupBQualifiers);

        $groupAFirst = $groupAQualifiers[0];
        $this->assertSame($setup['groupA']->id, $groupAFirst->groupId);
        $this->assertSame('Grupo A', $groupAFirst->groupName);
        $this->assertSame(1, $groupAFirst->groupPosition);
        $this->assertSame($setup['playerOne']->id, $groupAFirst->playerId);
        $this->assertSame('Jugador1 Test', $groupAFirst->playerName);
        $this->assertSame(1, $groupAFirst->won);
        $this->assertSame(0, $groupAFirst->lost);

        $groupASecond = $groupAQualifiers[1];
        $this->assertSame($setup['groupA']->id, $groupASecond->groupId);
        $this->assertSame('Grupo A', $groupASecond->groupName);
        $this->assertSame(2, $groupASecond->groupPosition);
        $this->assertSame($setup['playerTwo']->id, $groupASecond->playerId);
        $this->assertSame('Jugador2 Test', $groupASecond->playerName);
        $this->assertSame(0, $groupASecond->won);
        $this->assertSame(1, $groupASecond->lost);

        $groupBFirst = $groupBQualifiers[0];
        $this->assertSame($setup['groupB']->id, $groupBFirst->groupId);
        $this->assertSame('Grupo B', $groupBFirst->groupName);
        $this->assertSame(1, $groupBFirst->groupPosition);
        $this->assertSame($setup['playerThree']->id, $groupBFirst->playerId);
        $this->assertSame(1, $groupBFirst->won);
        $this->assertSame(0, $groupBFirst->lost);
    }

    public function test_identifies_first_second_and_third_positions_when_qualified_per_group_is_three(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        [$first, $second, $third] = $players;

        $group = $context->createGroupWithPlayers($competition, $players, 'Grupo A');
        $context->generateRoundRobin($group)->assertCreated();
        $this->finishGroupRoundRobinWithRankOrder($context, $group->id, $players);

        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        $qualifiers = app(GroupQualifiersCollector::class)->collect($competition->fresh());

        $this->assertCount(3, $qualifiers);

        $byPosition = $qualifiers->keyBy(fn (GroupQualifierData $qualifier): int => $qualifier->groupPosition);

        $this->assertSame(1, $byPosition[1]->groupPosition);
        $this->assertSame($first->id, $byPosition[1]->playerId);
        $this->assertSame('Grupo A', $byPosition[1]->groupName);
        $this->assertSame($group->id, $byPosition[1]->groupId);

        $this->assertSame(2, $byPosition[2]->groupPosition);
        $this->assertSame($second->id, $byPosition[2]->playerId);

        $this->assertSame(3, $byPosition[3]->groupPosition);
        $this->assertSame($third->id, $byPosition[3]->playerId);
    }

    public function test_excludes_withdrawn_and_disqualified_players(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        [$playerOne, $playerTwo, $playerThree] = $players;

        $group = $context->createGroupWithPlayers($competition, $players, 'Grupo A');
        $context->generateRoundRobin($group)->assertCreated();
        $this->finishGroupRoundRobinWithRankOrder($context, $group->id, $players);

        $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $playerOne->id,
            'status' => 'withdrawn',
        ])->assertCreated();

        $competition->update(['qualified_per_group' => 2]);
        $competition->refresh();

        $qualifiers = app(GroupQualifiersCollector::class)->collect($competition->fresh());

        $this->assertCount(2, $qualifiers);

        $playerIds = $qualifiers->pluck('playerId')->all();
        $this->assertNotContains($playerOne->id, $playerIds);
        $this->assertContains($playerTwo->id, $playerIds);
        $this->assertContains($playerThree->id, $playerIds);

        $byPosition = $qualifiers->keyBy(fn (GroupQualifierData $qualifier): int => $qualifier->groupPosition);
        $this->assertSame(1, $byPosition[1]->groupPosition);
        $this->assertSame($playerTwo->id, $byPosition[1]->playerId);
        $this->assertSame(2, $byPosition[2]->groupPosition);
        $this->assertSame($playerThree->id, $byPosition[2]->playerId);
    }

    public function test_respects_manual_tiebreak_order(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedTripleTie($context);
        $group = $setup['group'];
        $competition = $setup['competition'];
        [$playerA, $playerB, $playerC] = $setup['players'];

        $this->postJson($context->apiUrl("groups/{$group->id}/manual-tiebreaks"), [
            'player_ids' => [$playerB->id, $playerA->id, $playerC->id],
            'reason' => 'draw',
        ])->assertCreated();

        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        $qualifiers = app(GroupQualifiersCollector::class)->collect($competition->fresh());

        $this->assertCount(3, $qualifiers);

        $byPosition = $qualifiers->keyBy(fn (GroupQualifierData $qualifier): int => $qualifier->groupPosition);

        $this->assertSame($playerB->id, $byPosition[1]->playerId);
        $this->assertSame($playerA->id, $byPosition[2]->playerId);
        $this->assertSame($playerC->id, $byPosition[3]->playerId);
    }

    public function test_throws_when_manual_tiebreak_crosses_qualification_cutoff(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedTripleTie($context);
        $competition = $setup['competition'];

        $competition->update(['qualified_per_group' => 2]);
        $competition->refresh();

        $this->expectException(ValidationException::class);

        try {
            app(GroupQualifiersCollector::class)->collect($competition->fresh());
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('qualified_per_group', $exception->errors());

            throw $exception;
        }
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
     * @param  array<int, Player>  $playersInRankOrder
     */
    private function finishGroupRoundRobinWithRankOrder(
        TournamentTestContext $context,
        int $groupId,
        array $playersInRankOrder,
    ): void {
        $games = Game::query()->where('group_id', $groupId)->get();

        for ($index = 0; $index < count($playersInRankOrder); $index++) {
            for ($pairIndex = $index + 1; $pairIndex < count($playersInRankOrder); $pairIndex++) {
                $winner = $playersInRankOrder[$index];
                $left = $playersInRankOrder[$index];
                $right = $playersInRankOrder[$pairIndex];

                $game = $games->first(
                    fn (Game $candidate): bool => (
                        (int) $candidate->player1_id === $left->id && (int) $candidate->player2_id === $right->id
                    ) || (
                        (int) $candidate->player1_id === $right->id && (int) $candidate->player2_id === $left->id
                    )
                );

                $this->assertNotNull($game);
                $context->finishGame($game, $winner)->assertOk();
            }
        }
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
