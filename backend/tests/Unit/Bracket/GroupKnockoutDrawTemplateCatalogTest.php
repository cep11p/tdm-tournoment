<?php

namespace Tests\Unit\Bracket;

use App\Data\Bracket\GroupKnockoutDrawTemplate;
use App\Data\Bracket\GroupKnockoutTemplateMatch;
use App\Data\Bracket\GroupKnockoutTemplateSlot;
use App\Support\Bracket\GroupKnockoutDrawTemplateCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GroupKnockoutDrawTemplateCatalogTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function officialTemplateProvider(): array
    {
        return [
            '3 groups' => [3, 16, 7],
            '4 groups' => [4, 16, 4],
            '5 groups' => [5, 16, 1],
            '6 groups' => [6, 32, 14],
            '7 groups' => [7, 32, 11],
            '8 groups' => [8, 32, 8],
            '9 groups' => [9, 32, 5],
        ];
    }

    #[DataProvider('officialTemplateProvider')]
    public function test_official_templates_satisfy_structural_invariants(
        int $groupCount,
        int $bracketSize,
        int $expectedByes,
    ): void {
        $template = GroupKnockoutDrawTemplateCatalog::forGroupCount($groupCount);
        $matchCount = (int) ($bracketSize / 2);
        $qualifierCount = $groupCount * 3;

        $this->assertSame($groupCount, $template->groupCount);
        $this->assertSame(3, $template->qualifiedPerGroup);
        $this->assertSame(GroupKnockoutDrawTemplateCatalog::QUALIFIED_PER_GROUP, $template->qualifiedPerGroup);
        $this->assertSame($bracketSize, $template->bracketSize);
        $this->assertCount($matchCount, $template->matches);
        $this->assertSame($qualifierCount, $template->qualifierCount());
        $this->assertSame($expectedByes, $template->byesCount());
        $this->assertSame($bracketSize - $qualifierCount, $template->byesCount());

        $bracketMatches = array_map(
            fn (GroupKnockoutTemplateMatch $match): int => $match->bracketMatch,
            $template->matches,
        );

        $this->assertSame(range(1, $matchCount), $bracketMatches);

        $seenQualifiers = [];
        $byeCount = 0;

        foreach ($template->matches as $match) {
            $this->assertQualifierSlot(
                $match->side1,
                $groupCount,
                sprintf('side1 del partido %d', $match->bracketMatch),
            );

            $this->assertQualifierAppearsOnce($seenQualifiers, $match->side1);

            if ($match->side2 === null) {
                $this->assertTrue($match->isBye());
                $byeCount++;

                continue;
            }

            $this->assertFalse($match->isBye());
            $this->assertQualifierSlot(
                $match->side2,
                $groupCount,
                sprintf('side2 del partido %d', $match->bracketMatch),
            );
            $this->assertQualifierAppearsOnce($seenQualifiers, $match->side2);
        }

        $this->assertCount($qualifierCount, $seenQualifiers);
        $this->assertSame($expectedByes, $byeCount);
    }

    public function test_catalog_exposes_every_supported_group_count(): void
    {
        $this->assertSame([3, 4, 5, 6, 7, 8, 9], GroupKnockoutDrawTemplateCatalog::SUPPORTED_GROUP_COUNTS);

        $groupCounts = array_map(
            fn (GroupKnockoutDrawTemplate $template): int => $template->groupCount,
            GroupKnockoutDrawTemplateCatalog::all(),
        );

        $this->assertSame([3, 4, 5, 6, 7, 8, 9], $groupCounts);
        $this->assertTrue(GroupKnockoutDrawTemplateCatalog::supports(3));
        $this->assertTrue(GroupKnockoutDrawTemplateCatalog::supports(9));
        $this->assertFalse(GroupKnockoutDrawTemplateCatalog::supports(2));
        $this->assertFalse(GroupKnockoutDrawTemplateCatalog::supports(10));
    }

    public function test_rejects_unsupported_group_counts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No hay plantilla oficial de llave para 2 grupos');

        GroupKnockoutDrawTemplateCatalog::forGroupCount(2);
    }

    public function test_three_group_template_matches_official_draw(): void
    {
        $this->assertOfficialDraw(3, [
            1 => [[0, 1], null],
            2 => [[2, 3], [1, 3]],
            3 => [[1, 2], null],
            4 => [[2, 1], null],
            5 => [[2, 2], null],
            6 => [[0, 2], null],
            7 => [[0, 3], null],
            8 => [[1, 1], null],
        ]);
    }

    public function test_four_group_template_matches_official_draw(): void
    {
        $this->assertOfficialDraw(4, [
            1 => [[0, 1], null],
            2 => [[2, 2], [1, 3]],
            3 => [[2, 3], [1, 2]],
            4 => [[3, 1], null],
            5 => [[2, 1], null],
            6 => [[0, 2], [3, 3]],
            7 => [[0, 3], [3, 2]],
            8 => [[1, 1], null],
        ]);
    }

    public function test_five_group_template_matches_official_draw(): void
    {
        $this->assertOfficialDraw(5, [
            1 => [[0, 1], null],
            2 => [[2, 2], [1, 3]],
            3 => [[4, 1], [1, 2]],
            4 => [[0, 3], [3, 1]],
            5 => [[2, 1], [4, 3]],
            6 => [[0, 2], [3, 2]],
            7 => [[4, 2], [3, 3]],
            8 => [[2, 3], [1, 1]],
        ]);
    }

    public function test_six_group_template_matches_official_draw(): void
    {
        $this->assertOfficialDraw(6, [
            1 => [[0, 1], null],
            2 => [[3, 3], [4, 3]],
            3 => [[5, 2], null],
            4 => [[2, 2], null],
            5 => [[4, 1], null],
            6 => [[1, 2], null],
            7 => [[0, 3], null],
            8 => [[3, 1], null],
            9 => [[2, 1], null],
            10 => [[1, 3], null],
            11 => [[0, 2], null],
            12 => [[5, 1], null],
            13 => [[3, 2], null],
            14 => [[4, 2], null],
            15 => [[2, 3], [5, 3]],
            16 => [[1, 1], null],
        ]);
    }

    public function test_seven_group_template_matches_official_draw(): void
    {
        $this->assertOfficialDraw(7, [
            1 => [[0, 1], null],
            2 => [[3, 3], [4, 3]],
            3 => [[5, 2], null],
            4 => [[6, 2], null],
            5 => [[4, 1], null],
            6 => [[1, 2], [6, 3]],
            7 => [[2, 2], [0, 3]],
            8 => [[3, 1], null],
            9 => [[2, 1], null],
            10 => [[4, 2], [1, 3]],
            11 => [[0, 2], null],
            12 => [[5, 1], null],
            13 => [[6, 1], null],
            14 => [[3, 2], null],
            15 => [[5, 3], [2, 3]],
            16 => [[1, 1], null],
        ]);
    }

    public function test_eight_group_template_matches_official_draw(): void
    {
        $this->assertOfficialDraw(8, [
            1 => [[0, 1], null],
            2 => [[6, 2], [3, 3]],
            3 => [[5, 2], [4, 3]],
            4 => [[7, 1], null],
            5 => [[4, 1], null],
            6 => [[1, 2], [7, 3]],
            7 => [[2, 2], [0, 3]],
            8 => [[3, 1], null],
            9 => [[2, 1], null],
            10 => [[4, 2], [1, 3]],
            11 => [[0, 2], [6, 3]],
            12 => [[5, 1], null],
            13 => [[6, 1], null],
            14 => [[3, 2], [2, 3]],
            15 => [[7, 2], [5, 3]],
            16 => [[1, 1], null],
        ]);
    }

    public function test_nine_group_template_matches_official_draw(): void
    {
        $this->assertOfficialDraw(9, [
            1 => [[0, 1], null],
            2 => [[6, 2], [4, 3]],
            3 => [[8, 1], [3, 3]],
            4 => [[7, 1], [1, 3]],
            5 => [[4, 1], null],
            6 => [[1, 2], [2, 2]],
            7 => [[5, 2], [0, 3]],
            8 => [[3, 1], null],
            9 => [[2, 1], null],
            10 => [[3, 2], [8, 3]],
            11 => [[0, 2], [4, 2]],
            12 => [[5, 1], [7, 3]],
            13 => [[6, 1], [2, 3]],
            14 => [[7, 2], [8, 2]],
            15 => [[5, 3], [6, 3]],
            16 => [[1, 1], null],
        ]);
    }

    /**
     * @param  array<int, array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}|null}>  $expectedSidesByMatch
     */
    private function assertOfficialDraw(int $groupCount, array $expectedSidesByMatch): void
    {
        $template = GroupKnockoutDrawTemplateCatalog::forGroupCount($groupCount);

        $this->assertSame(array_keys($expectedSidesByMatch), array_keys($this->sidesByMatch($template)));
        $this->assertSame($expectedSidesByMatch, $this->sidesByMatch($template));
    }

    /**
     * @return array<int, array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}|null}>
     */
    private function sidesByMatch(GroupKnockoutDrawTemplate $template): array
    {
        $sides = [];

        foreach ($template->matches as $match) {
            $sides[$match->bracketMatch] = [
                [$match->side1->groupIndex, $match->side1->groupPosition],
                $match->side2 === null
                    ? null
                    : [$match->side2->groupIndex, $match->side2->groupPosition],
            ];
        }

        return $sides;
    }

    private function assertQualifierSlot(
        GroupKnockoutTemplateSlot $slot,
        int $groupCount,
        string $context,
    ): void {
        $this->assertGreaterThanOrEqual(0, $slot->groupIndex, $context);
        $this->assertLessThan($groupCount, $slot->groupIndex, $context);
        $this->assertGreaterThanOrEqual(1, $slot->groupPosition, $context);
        $this->assertLessThanOrEqual(3, $slot->groupPosition, $context);
    }

    /**
     * @param  array<string, true>  $seenQualifiers
     */
    private function assertQualifierAppearsOnce(
        array &$seenQualifiers,
        GroupKnockoutTemplateSlot $slot,
    ): void {
        $key = sprintf('%d:%d', $slot->groupIndex, $slot->groupPosition);

        $this->assertArrayNotHasKey(
            $key,
            $seenQualifiers,
            sprintf('La referencia %s aparece más de una vez.', $key),
        );

        $seenQualifiers[$key] = true;
    }
}
