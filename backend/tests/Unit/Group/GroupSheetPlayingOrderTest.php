<?php

namespace Tests\Unit\Group;

use App\Data\Group\GroupSheetFixture;
use App\Support\Group\GroupSheetPlayingOrder;
use App\Support\Group\GroupSheetUnsupportedSizeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GroupSheetPlayingOrderTest extends TestCase
{
    /**
     * @return array<string, array{0: int}>
     */
    public static function supportedSizeProvider(): array
    {
        return [
            'G3' => [3],
            'G4' => [4],
            'G5' => [5],
        ];
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function unsupportedSizeProvider(): array
    {
        return [
            '2 participantes' => [2],
            '6 participantes' => [6],
            '7 participantes' => [7],
            '1 participante' => [1],
            '0 participantes' => [0],
        ];
    }

    #[DataProvider('supportedSizeProvider')]
    public function test_supports_g3_g4_g5(int $size): void
    {
        $this->assertTrue(GroupSheetPlayingOrder::supports($size));
    }

    #[DataProvider('unsupportedSizeProvider')]
    public function test_does_not_support_other_sizes(int $size): void
    {
        $this->assertFalse(GroupSheetPlayingOrder::supports($size));
    }

    #[DataProvider('unsupportedSizeProvider')]
    public function test_for_size_rejects_unsupported_sizes(int $size): void
    {
        $this->expectException(GroupSheetUnsupportedSizeException::class);
        $this->expectExceptionMessage(sprintf(
            'No hay patrón operativo de planilla G3/G4/G5 para grupos de %d participantes.',
            $size,
        ));

        GroupSheetPlayingOrder::forSize($size);
    }

    public function test_g3_returns_exact_playing_order(): void
    {
        $this->assertSame(
            [
                [1, 3],
                [1, 2],
                [2, 3],
            ],
            $this->pairings(3),
        );
    }

    public function test_g4_returns_exact_playing_order(): void
    {
        $this->assertSame(
            [
                [1, 3],
                [2, 4],
                [1, 2],
                [3, 4],
                [1, 4],
                [2, 3],
            ],
            $this->pairings(4),
        );
    }

    public function test_g5_returns_exact_playing_order(): void
    {
        $this->assertSame(
            [
                [2, 5],
                [3, 4],
                [1, 5],
                [2, 3],
                [1, 4],
                [5, 3],
                [1, 3],
                [4, 2],
                [1, 2],
                [4, 5],
            ],
            $this->pairings(5),
        );
    }

    public function test_g5_preserves_sheet_side_orientation(): void
    {
        $pairings = $this->pairings(5);

        $this->assertSame([5, 3], $pairings[5]);
        $this->assertSame([4, 2], $pairings[7]);
        $this->assertNotSame([3, 5], $pairings[5]);
        $this->assertNotSame([2, 4], $pairings[7]);
    }

    #[DataProvider('supportedSizeProvider')]
    public function test_each_unordered_pair_appears_exactly_once(int $size): void
    {
        $unordered = [];

        foreach ($this->pairings($size) as $pairing) {
            $key = $this->unorderedPairKey($pairing[0], $pairing[1]);
            $this->assertArrayNotHasKey($key, $unordered, sprintf('La pareja %s aparece más de una vez.', $key));
            $unordered[$key] = true;
        }

        $this->assertCount($this->expectedMatchCount($size), $unordered);
    }

    #[DataProvider('supportedSizeProvider')]
    public function test_nobody_plays_against_themselves(int $size): void
    {
        foreach (GroupSheetPlayingOrder::forSize($size) as $fixture) {
            $this->assertNotSame(
                $fixture->side1,
                $fixture->side2,
                sprintf('El número %d juega contra sí mismo.', $fixture->side1),
            );
        }
    }

    public function test_match_counts(): void
    {
        $this->assertCount(3, GroupSheetPlayingOrder::forSize(3));
        $this->assertCount(6, GroupSheetPlayingOrder::forSize(4));
        $this->assertCount(10, GroupSheetPlayingOrder::forSize(5));
    }

    public function test_g3_has_three_rounds_of_one_match(): void
    {
        $this->assertRoundStructure(3, [
            1 => 1,
            2 => 1,
            3 => 1,
        ]);
    }

    public function test_g4_has_three_rounds_of_two_matches(): void
    {
        $this->assertRoundStructure(4, [
            1 => 2,
            2 => 2,
            3 => 2,
        ]);
    }

    public function test_g5_has_five_rounds_of_two_matches(): void
    {
        $this->assertRoundStructure(5, [
            1 => 2,
            2 => 2,
            3 => 2,
            4 => 2,
            5 => 2,
        ]);
    }

    #[DataProvider('supportedSizeProvider')]
    public function test_nobody_plays_twice_in_the_same_round(int $size): void
    {
        $playersByRound = [];

        foreach (GroupSheetPlayingOrder::forSize($size) as $fixture) {
            $playersByRound[$fixture->groupRound][] = $fixture->side1;
            $playersByRound[$fixture->groupRound][] = $fixture->side2;
        }

        foreach ($playersByRound as $round => $players) {
            $this->assertSame(
                count($players),
                count(array_unique($players)),
                sprintf('Un número de planilla aparece más de una vez en la ronda %d.', $round),
            );
        }
    }

    /**
     * @param  array<int, int>  $matchesPerRound
     */
    private function assertRoundStructure(int $size, array $matchesPerRound): void
    {
        $fixtures = GroupSheetPlayingOrder::forSize($size);
        $rounds = [];

        foreach ($fixtures as $fixture) {
            $rounds[$fixture->groupRound][] = $fixture;
        }

        $this->assertSame(array_keys($matchesPerRound), array_keys($rounds));

        foreach ($matchesPerRound as $round => $expectedMatchCount) {
            $this->assertCount($expectedMatchCount, $rounds[$round]);

            $matchNumbers = array_map(
                static fn (GroupSheetFixture $fixture): int => $fixture->groupMatch,
                $rounds[$round],
            );

            $this->assertSame(range(1, $expectedMatchCount), $matchNumbers);
        }
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function pairings(int $size): array
    {
        return array_map(
            static fn (GroupSheetFixture $fixture): array => [$fixture->side1, $fixture->side2],
            GroupSheetPlayingOrder::forSize($size),
        );
    }

    private function unorderedPairKey(int $side1, int $side2): string
    {
        $pair = [$side1, $side2];
        sort($pair);

        return sprintf('%d-%d', $pair[0], $pair[1]);
    }

    private function expectedMatchCount(int $size): int
    {
        return intdiv($size * ($size - 1), 2);
    }
}
