<?php

namespace Tests\Feature\Group;

use App\Enums\GroupPlayerStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Models\Group;
use App\Models\GroupEntry;
use App\Models\GroupManualTiebreakEntry;
use App\Models\Player;
use App\Support\Bracket\GroupBracketReadiness;
use App\Support\Bracket\GroupQualifiersCollector;
use App\Support\Competition\ResolveSinglesEntryForPlayer;
use App\Support\Group\GroupStandingsCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class GroupStandingsEntryIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_standings_identify_the_correct_competition_entry(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedThreePlayerGroup($context);
        $group = $setup['group'];
        [$playerOne] = $setup['players'];

        $entryOne = $this->entryFor($group->competition, $playerOne);
        $result = app(GroupStandingsCalculator::class)->calculate($group->fresh());
        $first = $result->standings->first();

        $this->assertSame($entryOne->id, $first->competitionEntryId);
        $this->assertSame($playerOne->id, $first->playerId);
        $this->assertSame(2, $first->won);
    }

    public function test_group_entry_status_controls_eligibility(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedThreePlayerGroup($context);
        $group = $setup['group'];
        [$playerOne] = $setup['players'];
        $entryOne = $this->entryFor($group->competition, $playerOne);

        GroupEntry::query()
            ->where('group_id', $group->id)
            ->where('competition_entry_id', $entryOne->id)
            ->update(['status' => GroupPlayerStatus::Withdrawn->value]);

        $result = app(GroupStandingsCalculator::class)->calculate($group->fresh());
        $standing = $result->standings->last();

        $this->assertSame($entryOne->id, $standing->competitionEntryId);
        $this->assertSame('withdrawn', $standing->groupPlayerStatus);
        $this->assertFalse($standing->eligibleForQualification);
    }

    public function test_disqualified_group_entry_is_not_eligible(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedThreePlayerGroup($context);
        $group = $setup['group'];
        [$playerOne] = $setup['players'];
        $entryOne = $this->entryFor($group->competition, $playerOne);

        GroupEntry::query()
            ->where('group_id', $group->id)
            ->where('competition_entry_id', $entryOne->id)
            ->update(['status' => GroupPlayerStatus::Disqualified->value]);

        $result = app(GroupStandingsCalculator::class)->calculate($group->fresh());
        $standing = $result->standings->last();

        $this->assertSame($entryOne->id, $standing->competitionEntryId);
        $this->assertSame('disqualified', $standing->groupPlayerStatus);
        $this->assertFalse($standing->eligibleForQualification);
    }

    public function test_legacy_game_results_are_credited_to_the_group_entry(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedThreePlayerGroup($context);
        $group = $setup['group'];
        [$playerOne, $playerTwo, $playerThree] = $setup['players'];

        $result = app(GroupStandingsCalculator::class)->calculate($group->fresh());
        $byPlayerId = $result->standings->keyBy('playerId');

        $this->assertSame(2, $byPlayerId[$playerOne->id]->won);
        $this->assertSame(0, $byPlayerId[$playerOne->id]->lost);
        $this->assertSame($this->entryFor($group->competition, $playerOne)->id, $byPlayerId[$playerOne->id]->competitionEntryId);

        $this->assertSame(1, $byPlayerId[$playerTwo->id]->won);
        $this->assertSame(1, $byPlayerId[$playerTwo->id]->lost);
        $this->assertSame($this->entryFor($group->competition, $playerTwo)->id, $byPlayerId[$playerTwo->id]->competitionEntryId);

        $this->assertSame(0, $byPlayerId[$playerThree->id]->won);
        $this->assertSame(2, $byPlayerId[$playerThree->id]->lost);
        $this->assertSame($this->entryFor($group->competition, $playerThree)->id, $byPlayerId[$playerThree->id]->competitionEntryId);
    }

    public function test_manual_tiebreak_persists_competition_entry_ids_and_not_player_id(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedTripleTie($context);
        $group = $setup['group'];
        [$playerA, $playerB, $playerC] = $setup['players'];

        $this->postJson($context->apiUrl("groups/{$group->id}/manual-tiebreaks"), [
            'player_ids' => [$playerB->id, $playerA->id, $playerC->id],
            'reason' => 'draw',
        ])->assertCreated();

        $this->assertFalse(Schema::hasTable('group_manual_tiebreak_players'));
        $this->assertFalse(Schema::hasColumn('group_manual_tiebreak_entries', 'player_id'));
        $this->assertTrue(Schema::hasColumn('group_manual_tiebreak_entries', 'competition_entry_id'));

        $orderedEntryIds = GroupManualTiebreakEntry::query()
            ->orderBy('position')
            ->pluck('competition_entry_id')
            ->map(fn (int $entryId): int => (int) $entryId)
            ->all();

        $this->assertSame([
            $this->entryFor($group->competition, $playerB)->id,
            $this->entryFor($group->competition, $playerA)->id,
            $this->entryFor($group->competition, $playerC)->id,
        ], $orderedEntryIds);
    }

    public function test_standings_json_includes_entry_identity_fields_for_singles(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedThreePlayerGroup($context);
        $group = $setup['group'];

        $response = $this->getJson($context->apiUrl("groups/{$group->id}/standings"));

        $response->assertOk();

        $standing = $response->json('data.0');
        $this->assertSame([
            'competition_entry_id',
            'display_name',
            'members',
            'player_id',
            'player_name',
            'played',
            'won',
            'lost',
            'requires_manual_tiebreak',
            'manual_tiebreak_applied',
            'manual_position',
            'eligible_for_qualification',
            'group_player_status',
        ], array_keys($standing));

        $this->assertNotNull($standing['player_id']);
        $this->assertNotNull($standing['player_name']);
        $this->assertSame($standing['player_name'], $standing['display_name']);
        $this->assertCount(1, $standing['members']);

        $meta = $response->json('meta');
        $this->assertSame([
            'standings_are_provisional',
            'completed_games_count',
            'total_games_count',
            'requires_manual_tiebreak',
            'manual_tiebreak_groups',
            'has_manual_tiebreaks',
            'manual_tiebreaks',
            'stale_manual_tiebreaks',
        ], array_keys($meta));
    }

    public function test_pending_manual_tie_groups_and_readiness_use_entry_ids(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createUnresolvedTripleTie($context);
        $group = $setup['group'];
        $competition = $setup['competition'];
        [$playerA, $playerB, $playerC] = $setup['players'];

        $entryIds = [
            $this->entryFor($competition, $playerA)->id,
            $this->entryFor($competition, $playerB)->id,
            $this->entryFor($competition, $playerC)->id,
        ];

        $result = app(GroupStandingsCalculator::class)->calculate($group->fresh());

        $this->assertCount(1, $result->pendingManualTieEntryGroups);
        $pending = $result->pendingManualTieEntryGroups[0];
        sort($pending);
        $expected = $entryIds;
        sort($expected);
        $this->assertSame($expected, $pending);

        $publicPlayerIds = $result->manualTiebreakGroups[0]['player_ids'] ?? [];
        sort($publicPlayerIds);
        $expectedPlayerIds = [$playerA->id, $playerB->id, $playerC->id];
        sort($expectedPlayerIds);
        $this->assertSame($expectedPlayerIds, $publicPlayerIds);

        $publicEntryIds = $result->manualTiebreakGroups[0]['entry_ids'];
        sort($publicEntryIds);
        sort($entryIds);
        $this->assertSame($entryIds, $publicEntryIds);

        $competition->update(['qualified_per_group' => 2]);

        $this->assertTrue(
            app(GroupBracketReadiness::class)->groupRequiresAttentionBeforeBracket($group->fresh(), 2)
        );
        $this->assertFalse(
            app(GroupBracketReadiness::class)->groupRequiresAttentionBeforeBracket($group->fresh(), 3)
        );
    }

    public function test_qualifiers_expose_entry_id_internally_and_derived_player_id(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedThreePlayerGroup($context);
        $competition = $setup['competition'];
        $players = $setup['players'];

        $competition->update(['qualified_per_group' => 2]);

        $qualifiers = app(GroupQualifiersCollector::class)->collect($competition->fresh());

        $this->assertCount(2, $qualifiers);
        $this->assertSame($players[0]->id, $qualifiers[0]->playerId);
        $this->assertSame($this->entryFor($competition, $players[0])->id, $qualifiers[0]->competitionEntryId);
        $this->assertSame($players[1]->id, $qualifiers[1]->playerId);
        $this->assertSame($this->entryFor($competition, $players[1])->id, $qualifiers[1]->competitionEntryId);
    }

    public function test_same_player_in_two_competitions_does_not_share_entry_identity(): void
    {
        $context = $this->tournamentContext();
        $players = $context->createPlayers(3);

        $competitionA = $context->createCompetition();
        $context->registerPlayers($competitionA, $players);
        $groupA = $context->createGroupWithPlayers($competitionA, $players, 'Grupo A');
        $context->generateRoundRobin($groupA)->assertCreated();
        $this->finishGamesInRankOrder($context, $groupA->id, $players);

        $competitionB = $context->createCompetition();
        $context->registerPlayers($competitionB, $players);
        $groupB = $context->createGroupWithPlayers($competitionB, $players, 'Grupo B');
        $context->generateRoundRobin($groupB)->assertCreated();
        $this->finishGamesInRankOrder($context, $groupB->id, array_reverse($players));

        $entryA = $this->entryFor($competitionA, $players[0]);
        $entryB = $this->entryFor($competitionB, $players[0]);
        $this->assertNotSame($entryA->id, $entryB->id);

        $standingsA = app(GroupStandingsCalculator::class)->calculate($groupA->fresh());
        $standingsB = app(GroupStandingsCalculator::class)->calculate($groupB->fresh());

        $this->assertSame($entryA->id, $standingsA->standings->first()->competitionEntryId);
        $this->assertSame($players[0]->id, $standingsA->standings->first()->playerId);
        $this->assertSame($entryB->id, $standingsB->standings->last()->competitionEntryId);
        $this->assertSame($players[0]->id, $standingsB->standings->last()->playerId);

        $competitionA->update(['qualified_per_group' => 1]);
        $competitionB->update(['qualified_per_group' => 1]);

        $qualifiersA = app(GroupQualifiersCollector::class)->collect($competitionA->fresh());
        $qualifiersB = app(GroupQualifiersCollector::class)->collect($competitionB->fresh());

        $this->assertSame($entryA->id, $qualifiersA->first()->competitionEntryId);
        $this->assertSame($this->entryFor($competitionB, $players[2])->id, $qualifiersB->first()->competitionEntryId);
        $this->assertNotSame($entryB->id, $qualifiersB->first()->competitionEntryId);
        $this->assertSame($players[0]->id, $qualifiersA->first()->playerId);
        $this->assertSame($players[2]->id, $qualifiersB->first()->playerId);
    }

    public function test_standings_calculation_does_not_query_members_per_player(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFinishedThreePlayerGroup($context);
        $group = $setup['group'];

        Model::preventLazyLoading();
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            app(GroupStandingsCalculator::class)->calculate($group->fresh());
            $queryLog = DB::getQueryLog();
        } finally {
            Model::preventLazyLoading(false);
            DB::disableQueryLog();
        }

        $memberQueries = collect($queryLog)
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'competition_entry_members'))
            ->count();

        $this->assertLessThanOrEqual(2, $memberQueries);
        $this->assertGreaterThan(0, $memberQueries);
    }

    /**
     * @return array{
     *     competition: Competition,
     *     group: Group,
     *     players: array<int, Player>
     * }
     */
    private function createFinishedThreePlayerGroup(TournamentTestContext $context): array
    {
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();
        $this->finishGamesInRankOrder($context, $group->id, $players);

        return [
            'competition' => $competition,
            'group' => $group,
            'players' => $players,
        ];
    }

    /**
     * @return array{
     *     competition: Competition,
     *     group: Group,
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
    private function finishGamesInRankOrder(
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
                $game = $context->findGameBetween($games, $left, $right);
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
            $player1IsLeft = (int) $game->singlesPlayer1Id() === $leftPlayer->id;
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

    private function entryFor(Competition $competition, Player $player): CompetitionEntry
    {
        return app(ResolveSinglesEntryForPlayer::class)($competition, $player->id);
    }
}
