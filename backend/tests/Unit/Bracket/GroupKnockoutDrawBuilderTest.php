<?php

namespace Tests\Unit\Bracket;

use App\Data\Competition\GroupQualifierData;
use App\Support\Bracket\GroupKnockoutDrawBuilder;
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
        $qualifiers = collect([
            $this->qualifier(playerId: 101, groupId: 1, groupName: 'Grupo A', position: 1),
            $this->qualifier(playerId: 102, groupId: 1, groupName: 'Grupo A', position: 2),
            $this->qualifier(playerId: 201, groupId: 2, groupName: 'Grupo B', position: 1),
            $this->qualifier(playerId: 202, groupId: 2, groupName: 'Grupo B', position: 2),
            $this->qualifier(playerId: 301, groupId: 3, groupName: 'Grupo C', position: 1),
            $this->qualifier(playerId: 302, groupId: 3, groupName: 'Grupo C', position: 2),
            $this->qualifier(playerId: 401, groupId: 4, groupName: 'Grupo D', position: 1),
            $this->qualifier(playerId: 402, groupId: 4, groupName: 'Grupo D', position: 2),
        ]);

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

        $this->assertSame([101, 402], $matches[0]);
        $this->assertSame([201, 302], $matches[1]);
        $this->assertSame([301, 202], $matches[2]);
        $this->assertSame([401, 102], $matches[3]);
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

    public function test_rejects_unsupported_qualified_per_group_three(): void
    {
        $qualifiers = collect([
            $this->qualifier(playerId: 1, groupId: 10, groupName: 'Grupo A', position: 1),
            $this->qualifier(playerId: 2, groupId: 10, groupName: 'Grupo A', position: 2),
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(GroupKnockoutDrawBuilder::class)->build($qualifiers, 3);
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
