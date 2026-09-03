<?php

namespace Tests\Unit\Competition;

use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionFormat;
use App\Enums\GroupPlayerStatus;
use App\Models\Game;
use App\Models\Player;
use App\Support\Competition\GroupEliminatedEntriesFinalPositionResolver;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class GroupEliminatedEntriesFinalPositionResolverTest extends TestCase
{
    public function test_two_groups_of_four_share_thirds_and_fourths(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);

        $groupA = $context->createGroupWithPlayers($competition, array_slice($players, 0, 4), 'Grupo A');
        $groupB = $context->createGroupWithPlayers($competition, array_slice($players, 4, 4), 'Grupo B');
        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();
        $this->finishGroupRoundRobinWithRankOrder($context, $groupA->id, array_slice($players, 0, 4));
        $this->finishGroupRoundRobinWithRankOrder($context, $groupB->id, array_slice($players, 4, 4));

        $context->createBracket($competition)->assertCreated();

        $bracketEntryIds = $this->bracketEntryIds($competition->id);
        $this->assertCount(4, $bracketEntryIds);

        $placements = $this->byEntry(
            app(GroupEliminatedEntriesFinalPositionResolver::class)->resolve($competition->fresh(), $bracketEntryIds),
        );

        $this->assertCount(4, $placements);

        $thirdA = $context->entryIdFor($competition, $players[2]);
        $fourthA = $context->entryIdFor($competition, $players[3]);
        $thirdB = $context->entryIdFor($competition, $players[6]);
        $fourthB = $context->entryIdFor($competition, $players[7]);

        $this->assertSame(5, $placements[$thirdA]->position);
        $this->assertSame(6, $placements[$thirdA]->positionRangeEnd);
        $this->assertSame(CompetitionFinalStandingSource::GroupStage, $placements[$thirdA]->source);
        $this->assertSame(5, $placements[$thirdB]->position);
        $this->assertSame(6, $placements[$thirdB]->positionRangeEnd);

        $this->assertSame(7, $placements[$fourthA]->position);
        $this->assertSame(8, $placements[$fourthA]->positionRangeEnd);
        $this->assertSame(7, $placements[$fourthB]->position);
        $this->assertSame(8, $placements[$fourthB]->positionRangeEnd);
    }

    public function test_single_group_keeps_sporting_order_for_eliminated_entries(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players, 'Grupo A');
        $context->generateRoundRobin($group)->assertCreated();
        $this->finishGroupRoundRobinWithRankOrder($context, $group->id, $players);
        $context->createBracket($competition)->assertCreated();

        $bracketEntryIds = $this->bracketEntryIds($competition->id);
        $this->assertCount(2, $bracketEntryIds);

        $placements = $this->byEntry(
            app(GroupEliminatedEntriesFinalPositionResolver::class)->resolve($competition->fresh(), $bracketEntryIds),
        );

        $third = $context->entryIdFor($competition, $players[2]);
        $fourth = $context->entryIdFor($competition, $players[3]);

        $this->assertCount(2, $placements);
        $this->assertSame(3, $placements[$third]->position);
        $this->assertSame(3, $placements[$third]->positionRangeEnd);
        $this->assertSame(4, $placements[$fourth]->position);
        $this->assertSame(4, $placements[$fourth]->positionRangeEnd);
        $this->assertSame(CompetitionFinalStandingSource::GroupStage, $placements[$third]->source);
    }

    public function test_unequal_groups_share_first_non_qualifiers_then_remaining_offset(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createCompetition();
        $players = $context->createPlayers(7);
        $context->registerPlayers($competition, $players);

        $groupA = $context->createGroupWithPlayers($competition, array_slice($players, 0, 4), 'Grupo A');
        $groupB = $context->createGroupWithPlayers($competition, array_slice($players, 4, 3), 'Grupo B');
        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();
        $this->finishGroupRoundRobinWithRankOrder($context, $groupA->id, array_slice($players, 0, 4));
        $this->finishGroupRoundRobinWithRankOrder($context, $groupB->id, array_slice($players, 4, 3));
        $context->createBracket($competition)->assertCreated();

        $bracketEntryIds = $this->bracketEntryIds($competition->id);
        $this->assertCount(4, $bracketEntryIds);

        $placements = $this->byEntry(
            app(GroupEliminatedEntriesFinalPositionResolver::class)->resolve($competition->fresh(), $bracketEntryIds),
        );

        $aThird = $context->entryIdFor($competition, $players[2]);
        $aFourth = $context->entryIdFor($competition, $players[3]);
        $bThird = $context->entryIdFor($competition, $players[6]);

        $this->assertCount(3, $placements);
        $this->assertSame(5, $placements[$aThird]->position);
        $this->assertSame(6, $placements[$aThird]->positionRangeEnd);
        $this->assertSame(5, $placements[$bThird]->position);
        $this->assertSame(6, $placements[$bThird]->positionRangeEnd);
        $this->assertSame(7, $placements[$aFourth]->position);
        $this->assertSame(7, $placements[$aFourth]->positionRangeEnd);
    }

    public function test_withdrawn_entry_is_persisted_in_lowest_group_band(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players, 'Grupo A');
        $context->generateRoundRobin($group)->assertCreated();

        $this->postJson($context->apiUrl("groups/{$group->id}/player-status"), [
            'player_id' => $players[3]->id,
            'status' => GroupPlayerStatus::Withdrawn->value,
        ])->assertCreated();

        $this->finishRemainingGroupGames($context, $group->id, array_slice($players, 0, 3));
        $context->createBracket($competition)->assertCreated();

        $bracketEntryIds = $this->bracketEntryIds($competition->id);
        $placements = $this->byEntry(
            app(GroupEliminatedEntriesFinalPositionResolver::class)->resolve($competition->fresh(), $bracketEntryIds),
        );

        $withdrawn = $context->entryIdFor($competition, $players[3]);
        $this->assertArrayHasKey($withdrawn, $placements);
        $this->assertSame(CompetitionFinalStandingSource::GroupStage, $placements[$withdrawn]->source);

        $maxRangeEnd = max(array_map(fn ($dto) => $dto->positionRangeEnd, $placements));
        $this->assertSame($maxRangeEnd, $placements[$withdrawn]->positionRangeEnd);
    }

    public function test_knockout_direct_returns_no_group_eliminated_rows(): void
    {
        $context = $this->tournamentContext();
        $this->withHeaders($this->authHeaders(['organizer']));

        $competition = $context->createCompetition(format: CompetitionFormat::KnockoutDirect);
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $placements = app(GroupEliminatedEntriesFinalPositionResolver::class)->resolve(
            $competition->fresh(),
            $this->bracketEntryIds($competition->id),
        );

        $this->assertSame([], $placements);
    }

    /**
     * @return array<int, true>
     */
    private function bracketEntryIds(int $competitionId): array
    {
        $ids = [];

        foreach (Game::query()->where('competition_id', $competitionId)->whereNotNull('bracket_id')->get() as $game) {
            if ($game->entry1_id !== null) {
                $ids[(int) $game->entry1_id] = true;
            }

            if ($game->entry2_id !== null) {
                $ids[(int) $game->entry2_id] = true;
            }
        }

        return $ids;
    }

    /**
     * @param  list<\App\Data\Competition\CompetitionFinalStandingData>  $dtos
     * @return array<int, \App\Data\Competition\CompetitionFinalStandingData>
     */
    private function byEntry(array $dtos): array
    {
        $map = [];

        foreach ($dtos as $dto) {
            $map[$dto->competitionEntryId] = $dto;
        }

        return $map;
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
                $game = $context->findGameBetween($games, $left, $right);
                $context->finishGame($game, $winner)->assertOk();
            }
        }
    }

    /**
     * @param  array<int, Player>  $activePlayersInRankOrder
     */
    private function finishRemainingGroupGames(
        TournamentTestContext $context,
        int $groupId,
        array $activePlayersInRankOrder,
    ): void {
        $games = Game::query()
            ->where('group_id', $groupId)
            ->where('status', '!=', \App\Enums\GameStatus::Finished)
            ->get();

        foreach ($games as $game) {
            if ($game->is_bye || $game->entry1_id === null || $game->entry2_id === null) {
                continue;
            }

            $winner = $game->singlesPlayer1();
            $context->finishGame($game, $winner)->assertOk();
        }
    }
}
