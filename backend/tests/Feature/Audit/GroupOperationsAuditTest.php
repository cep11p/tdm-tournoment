<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Models\Game;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class GroupOperationsAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapKeycloak();
        $this->resetKeycloakClock();
        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_regenerate_creates_exactly_one_activity_with_counters(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);
        $context->generateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $this->assertDatabaseCount('activity_log', 0);

        $context->regenerateRandomGroups($competition, groupsCount: 2)->assertCreated();

        $this->assertDatabaseCount('activity_log', 1);

        $activity = Activity::query()->sole();

        $this->assertSame(AuditAction::GROUPS_REGENERATED->value, $activity->description);
        $this->assertSame('groups', $activity->log_name);
        $this->assertSame(2, data_get($activity->properties, 'old.groups_count'));
        $this->assertSame(2, data_get($activity->properties, 'new.groups_count'));
        $this->assertSame(2, data_get($activity->properties, 'summary.groups_removed'));
        $this->assertSame(2, data_get($activity->properties, 'summary.groups_created'));
        $this->assertSame(6, data_get($activity->properties, 'summary.players_assigned'));
        $this->assertSame(6, data_get($activity->properties, 'summary.games_created'));
    }

    public function test_player_status_change_creates_one_activity_with_old_and_new_status(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createGroupWithRoundRobin($context, playerCount: 3);
        $group = $setup['group'];
        $player = $setup['players'][0];

        $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $player->id,
            'status' => 'withdrawn',
            'reason' => 'no_show',
            'notes' => 'No se presentó',
        ])->assertCreated();

        $activity = Activity::query()
            ->where('description', AuditAction::GROUP_PLAYER_STATUS_CHANGED->value)
            ->sole();

        $this->assertSame('groups', $activity->log_name);
        $this->assertSame('active', data_get($activity->properties, 'old.status'));
        $this->assertSame('withdrawn', data_get($activity->properties, 'new.status'));
        $this->assertSame('no_show', data_get($activity->properties, 'new.reason_code'));
        $this->assertSame($player->id, data_get($activity->properties, 'summary.player_id'));
        $this->assertSame('No se presentó', data_get($activity->properties, 'reason'));
    }

    public function test_manual_tiebreak_creates_one_activity_with_player_order(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedTripleTie($context);
        $group = $setup['group'];
        [$playerA, $playerB, $playerC] = $setup['players'];

        $this->postJson($context->apiUrl("groups/{$group->id}/manual-tiebreaks"), [
            'player_ids' => [$playerB->id, $playerA->id, $playerC->id],
            'reason' => 'draw',
            'notes' => 'Sorteo entre empatados',
        ])->assertCreated();

        $activity = Activity::query()
            ->where('description', AuditAction::GROUP_MANUAL_TIEBREAK_APPLIED->value)
            ->sole();

        $this->assertSame('groups', $activity->log_name);
        $this->assertSame([$playerB->id, $playerA->id, $playerC->id], data_get($activity->properties, 'new.ordered_player_ids'));
        $this->assertSame([1, 2, 3], data_get($activity->properties, 'summary.positions_affected'));
        $this->assertSame('Sorteo entre empatados', data_get($activity->properties, 'reason'));
    }

    public function test_validation_error_does_not_create_activity(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);

        $context->regenerateRandomGroups($competition, groupsCount: 2)
            ->assertUnprocessable();

        $this->assertDatabaseCount('activity_log', 0);
    }

    /**
     * @return array{group: \App\Models\Group, players: array<int, \App\Models\Player>}
     */
    private function createGroupWithRoundRobin(TournamentTestContext $context, int $playerCount): array
    {
        $competition = $context->createCompetition();
        $players = $context->createPlayers($playerCount);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        return [
            'group' => $group,
            'players' => $players,
        ];
    }

    /**
     * @return array{
     *     group: \App\Models\Group,
     *     players: array<int, \App\Models\Player>
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
        \App\Models\Player $leftPlayer,
        \App\Models\Player $rightPlayer,
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
