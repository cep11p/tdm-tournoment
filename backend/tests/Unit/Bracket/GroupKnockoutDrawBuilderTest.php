<?php

namespace Tests\Unit\Bracket;

use App\Data\Bracket\GroupKnockoutDrawResult;
use App\Data\Competition\GroupQualifierData;
use App\Support\Bracket\BracketSupport;
use App\Support\Bracket\GroupKnockoutDrawBuilder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GroupKnockoutDrawBuilderTest extends TestCase
{
    public function test_builds_two_group_draw_as_a1_vs_b2_and_b1_vs_a2(): void
    {
        $qualifiers = collect([
            $this->qualifier(playerId: 1, groupId: 10, groupName: 'Grupo A', position: 1),
            $this->qualifier(playerId: 2, groupId: 10, groupName: 'Grupo A', position: 2),
            $this->qualifier(playerId: 3, groupId: 20, groupName: 'Grupo B', position: 1),
            $this->qualifier(playerId: 4, groupId: 20, groupName: 'Grupo B', position: 2),
        ]);

        $playerIds = app(GroupKnockoutDrawBuilder::class)->build($qualifiers, 2);

        $this->assertSame([1, 3, 2, 4], $playerIds);

        $matches = $this->firstRoundMatchesFromPlayerIds($playerIds, 4);

        $this->assertSame([1, 4], $matches[0]);
        $this->assertSame([3, 2], $matches[1]);
    }

    public function test_builds_four_group_draw_without_same_group_first_round_matches(): void
    {
        $qualifiers = $this->qualifiersForNamedGroups(['A', 'B', 'C', 'D'], qualifiedPerGroup: 2);

        $playerIds = app(GroupKnockoutDrawBuilder::class)->build($qualifiers, 2);

        $this->assertSame(
            [101, 201, 301, 401, 102, 202, 302, 402],
            $playerIds,
        );

        $matches = $this->firstRoundMatchesFromPlayerIds($playerIds, 8);
        $groupByPlayer = $qualifiers->keyBy('playerId');

        foreach ($matches as [$topPlayerId, $bottomPlayerId]) {
            $this->assertNotSame(
                $groupByPlayer[$topPlayerId]->groupId,
                $groupByPlayer[$bottomPlayerId]->groupId,
            );
        }
    }

    public function test_group_knockout_q2_does_not_use_global_record_for_byes_or_seeding(): void
    {
        $qualifiers = collect([
            $this->qualifier(playerId: 1, groupId: 10, groupName: 'Grupo A', position: 1, won: 1, lost: 0),
            $this->qualifier(playerId: 2, groupId: 10, groupName: 'Grupo A', position: 2, won: 2, lost: 0),
            $this->qualifier(playerId: 3, groupId: 20, groupName: 'Grupo B', position: 1, won: 0, lost: 1),
            $this->qualifier(playerId: 4, groupId: 20, groupName: 'Grupo B', position: 2, won: 1, lost: 1),
        ]);

        $playerIds = app(GroupKnockoutDrawBuilder::class)->build($qualifiers, 2);
        $matches = $this->firstRoundMatchesFromPlayerIds($playerIds, 4);

        $this->assertSame([1, 4], $matches[0]);
        $this->assertSame([3, 2], $matches[1]);
    }

    public function test_rejects_q2_draw_when_total_qualifiers_is_not_power_of_two(): void
    {
        $qualifiers = collect([
            $this->qualifier(playerId: 1, groupId: 10, groupName: 'Grupo A', position: 1),
            $this->qualifier(playerId: 2, groupId: 10, groupName: 'Grupo A', position: 2),
            $this->qualifier(playerId: 3, groupId: 20, groupName: 'Grupo B', position: 1),
            $this->qualifier(playerId: 4, groupId: 20, groupName: 'Grupo B', position: 2),
            $this->qualifier(playerId: 5, groupId: 30, groupName: 'Grupo C', position: 1),
            $this->qualifier(playerId: 6, groupId: 30, groupName: 'Grupo C', position: 2),
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(GroupKnockoutDrawBuilder::class)->build($qualifiers, 2);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('qualified_per_group', $exception->errors());

            throw $exception;
        }
    }

    public function test_builds_four_group_q3_draw_with_byes_for_first_places(): void
    {
        $qualifiers = $this->qualifiersForNamedGroups(['A', 'B', 'C', 'D'], qualifiedPerGroup: 3);
        $draw = app(GroupKnockoutDrawBuilder::class)->buildDraw($qualifiers, 3);

        $this->assertSame(16, $draw->bracketSize);
        $this->assertSame(4, $draw->byesCount);
        $this->assertSame(BracketSupport::PLAY_IN_ROUND_LABEL, $draw->firstRoundLabel);
        $this->assertCount(8, $draw->matches);

        $byeMatches = collect($draw->matches)->filter(fn ($match) => $match->isBye)->values();
        $this->assertCount(4, $byeMatches);

        $firstPlayerIds = $qualifiers
            ->filter(fn (GroupQualifierData $qualifier): bool => $qualifier->groupPosition === 1)
            ->pluck('playerId')
            ->all();

        foreach ($byeMatches as $byeMatch) {
            $this->assertContains($byeMatch->player1Id, $firstPlayerIds);
            $this->assertNull($byeMatch->player2Id);
        }
    }

    public function test_builds_four_group_q3_play_in_as_second_vs_third_from_different_groups(): void
    {
        $qualifiers = $this->qualifiersForNamedGroups(['A', 'B', 'C', 'D'], qualifiedPerGroup: 3);
        $draw = app(GroupKnockoutDrawBuilder::class)->buildDraw($qualifiers, 3);
        $groupByPlayer = $qualifiers->keyBy('playerId');

        $playInMatches = collect($draw->matches)->filter(fn ($match) => ! $match->isBye)->values();

        $this->assertCount(4, $playInMatches);

        $expectedPlayIns = [
            [102, 403],
            [202, 303],
            [302, 203],
            [402, 103],
        ];

        $actualPlayIns = $playInMatches
            ->map(fn ($match) => [$match->player1Id, $match->player2Id])
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing($expectedPlayIns, $actualPlayIns);

        foreach ($playInMatches as $playInMatch) {
            $this->assertSame(2, $groupByPlayer[$playInMatch->player1Id]->groupPosition);
            $this->assertSame(3, $groupByPlayer[$playInMatch->player2Id]->groupPosition);
            $this->assertNotSame(
                $groupByPlayer[$playInMatch->player1Id]->groupId,
                $groupByPlayer[$playInMatch->player2Id]->groupId,
            );
        }
    }

    public function test_builds_four_group_q3_without_same_group_pairing_after_play_in(): void
    {
        $qualifiers = $this->qualifiersForNamedGroups(['A', 'B', 'C', 'D'], qualifiedPerGroup: 3);
        $draw = app(GroupKnockoutDrawBuilder::class)->buildDraw($qualifiers, 3);

        $this->assertDrawAvoidsSameGroupRoundTwoPairings($qualifiers, $draw);
    }

    public function test_builds_eight_group_q3_draw_with_eight_byes(): void
    {
        $qualifiers = $this->qualifiersForNamedGroups(
            ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'],
            qualifiedPerGroup: 3,
        );
        $draw = app(GroupKnockoutDrawBuilder::class)->buildDraw($qualifiers, 3);

        $this->assertSame(32, $draw->bracketSize);
        $this->assertSame(8, $draw->byesCount);
        $this->assertCount(16, $draw->matches);

        $byeMatches = collect($draw->matches)->filter(fn ($match) => $match->isBye);
        $playInMatches = collect($draw->matches)->filter(fn ($match) => ! $match->isBye);

        $this->assertCount(8, $byeMatches);
        $this->assertCount(8, $playInMatches);

        $this->assertDrawAvoidsSameGroupRoundTwoPairings($qualifiers, $draw);
    }

    public function test_rejects_q3_when_missing_third_place(): void
    {
        $qualifiers = collect([
            $this->qualifier(playerId: 101, groupId: 1, groupName: 'Grupo A', position: 1),
            $this->qualifier(playerId: 102, groupId: 1, groupName: 'Grupo A', position: 2),
            $this->qualifier(playerId: 201, groupId: 2, groupName: 'Grupo B', position: 1),
            $this->qualifier(playerId: 202, groupId: 2, groupName: 'Grupo B', position: 2),
            $this->qualifier(playerId: 203, groupId: 2, groupName: 'Grupo B', position: 3),
            $this->qualifier(playerId: 301, groupId: 3, groupName: 'Grupo C', position: 1),
            $this->qualifier(playerId: 302, groupId: 3, groupName: 'Grupo C', position: 2),
            $this->qualifier(playerId: 401, groupId: 4, groupName: 'Grupo D', position: 1),
            $this->qualifier(playerId: 402, groupId: 4, groupName: 'Grupo D', position: 2),
            $this->qualifier(playerId: 403, groupId: 4, groupName: 'Grupo D', position: 3),
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(GroupKnockoutDrawBuilder::class)->buildDraw($qualifiers, 3);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('qualified_per_group', $exception->errors());

            throw $exception;
        }
    }

    public function test_rejects_q3_when_compatible_assignment_does_not_exist(): void
    {
        $qualifiers = $this->qualifiersForNamedGroups(['A', 'B'], qualifiedPerGroup: 3);

        $this->expectException(ValidationException::class);

        try {
            app(GroupKnockoutDrawBuilder::class)->buildDraw($qualifiers, 3);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('qualified_per_group', $exception->errors());

            throw $exception;
        }
    }

    /**
     * @return array<int, array{0: int, 1: int}>
     */
    private function firstRoundMatchesFromPlayerIds(array $playerIds, int $bracketSize): array
    {
        $matchCount = (int) ($bracketSize / 2);
        $matches = [];

        for ($matchIndex = 0; $matchIndex < $matchCount; $matchIndex++) {
            $topSeed = $matchIndex + 1;
            $bottomSeed = $bracketSize - $matchIndex;

            $matches[] = [
                $playerIds[$topSeed - 1],
                $playerIds[$bottomSeed - 1],
            ];
        }

        return $matches;
    }

    /**
     * @param  list<string>  $groupNames
     * @return Collection<int, GroupQualifierData>
     */
    private function qualifiersForNamedGroups(array $groupNames, int $qualifiedPerGroup): Collection
    {
        $qualifiers = collect();

        foreach ($groupNames as $groupIndex => $groupName) {
            $groupId = ($groupIndex + 1) * 100;

            for ($position = 1; $position <= $qualifiedPerGroup; $position++) {
                $playerId = $groupId + $position;
                $qualifiers->push($this->qualifier(
                    playerId: $playerId,
                    groupId: $groupId,
                    groupName: 'Grupo ' . $groupName,
                    position: $position,
                ));
            }
        }

        return $qualifiers;
    }

    private function assertDrawAvoidsSameGroupRoundTwoPairings(
        Collection $qualifiers,
        GroupKnockoutDrawResult $draw,
    ): void {
        $groupByPlayer = $qualifiers->keyBy('playerId');
        $matchesByNumber = collect($draw->matches)->keyBy('bracketMatch');

        for ($pairIndex = 0; $pairIndex < ($draw->byesCount); $pairIndex++) {
            $byeMatchNumber = ($pairIndex * 2) + 1;
            $playInMatchNumber = $byeMatchNumber + 1;

            $byeMatch = $matchesByNumber[$byeMatchNumber];
            $playInMatch = $matchesByNumber[$playInMatchNumber];

            $firstGroupId = $groupByPlayer[$byeMatch->player1Id]->groupId;
            $playInGroupIds = [
                $groupByPlayer[$playInMatch->player1Id]->groupId,
                $groupByPlayer[$playInMatch->player2Id]->groupId,
            ];

            $this->assertNotContains($firstGroupId, $playInGroupIds);
        }
    }

    private function qualifier(
        int $playerId,
        int $groupId,
        string $groupName,
        int $position,
        int $won = 0,
        int $lost = 0,
    ): GroupQualifierData {
        return new GroupQualifierData(
            playerId: $playerId,
            playerName: sprintf('Jugador %d', $playerId),
            groupId: $groupId,
            groupName: $groupName,
            groupPosition: $position,
            won: $won,
            lost: $lost,
        );
    }
}
