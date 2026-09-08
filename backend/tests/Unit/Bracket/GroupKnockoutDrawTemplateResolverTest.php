<?php

namespace Tests\Unit\Bracket;

use App\Data\Bracket\BracketDrawMatchData;
use App\Data\Bracket\GroupKnockoutDrawResult;
use App\Data\Bracket\GroupKnockoutDrawTemplate;
use App\Data\Bracket\GroupKnockoutTemplateMatch;
use App\Data\Bracket\GroupKnockoutTemplateSlot;
use App\Data\Competition\GroupQualifierData;
use App\Support\Bracket\BracketSupport;
use App\Support\Bracket\GroupKnockoutDrawTemplateCatalog;
use App\Support\Bracket\GroupKnockoutDrawTemplateResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GroupKnockoutDrawTemplateResolverTest extends TestCase
{
    public function test_resolves_first_place_of_group_a(): void
    {
        $result = $this->resolver()->resolve(
            $this->exampleTemplate(),
            $this->qualifiersForNamedGroups(['A', 'B', 'C']),
        );

        $this->assertSame(101, $result->matches[0]->entry1Id);
    }

    public function test_resolves_second_place_of_group_c(): void
    {
        $template = $this->template(3, 16, [
            $this->match(1, $this->slot(2, 2), null),
        ]);

        $result = $this->resolver()->resolve(
            $template,
            $this->qualifiersForNamedGroups(['A', 'B', 'C']),
        );

        $this->assertSame(302, $result->matches[0]->entry1Id);
        $this->assertNull($result->matches[0]->entry2Id);
    }

    public function test_resolves_a_real_match(): void
    {
        $result = $this->resolver()->resolve(
            $this->exampleTemplate(),
            $this->qualifiersForNamedGroups(['A', 'B', 'C']),
        );

        $match = $result->matches[1];

        $this->assertSame(2, $match->bracketMatch);
        $this->assertSame(303, $match->entry1Id);
        $this->assertSame(203, $match->entry2Id);
        $this->assertFalse($match->isBye);
    }

    public function test_resolves_a_bye_only_when_side2_is_null(): void
    {
        $result = $this->resolver()->resolve(
            $this->exampleTemplate(),
            $this->qualifiersForNamedGroups(['A', 'B', 'C']),
        );

        $match = $result->matches[0];

        $this->assertSame(1, $match->bracketMatch);
        $this->assertSame(101, $match->entry1Id);
        $this->assertNull($match->entry2Id);
        $this->assertTrue($match->isBye);
    }

    public function test_preserves_exact_bracket_match_numbers(): void
    {
        $template = $this->template(3, 16, [
            $this->match(7, $this->slot(0, 1), null),
            $this->match(12, $this->slot(2, 3), $this->slot(1, 3)),
        ]);

        $result = $this->resolver()->resolve(
            $template,
            $this->qualifiersForNamedGroups(['A', 'B', 'C']),
        );

        $this->assertSame(7, $result->matches[0]->bracketMatch);
        $this->assertSame(12, $result->matches[1]->bracketMatch);
    }

    public function test_preserves_template_bracket_size_without_recalculating(): void
    {
        $template = $this->template(3, 32, [
            $this->match(1, $this->slot(0, 1), null),
        ]);

        $result = $this->resolver()->resolve(
            $template,
            $this->qualifiersForNamedGroups(['A', 'B', 'C']),
        );

        $this->assertSame(32, $result->bracketSize);
        $this->assertSame(32, $template->bracketSize);
        $this->assertNotSame(BracketSupport::nextPowerOfTwo(9), $result->bracketSize);
        $this->assertSame('16avos de final', $result->firstRoundLabel);
    }

    public function test_preserves_template_byes_count_without_recalculating(): void
    {
        $template = $this->template(3, 32, [
            $this->match(1, $this->slot(0, 1), null),
        ]);

        $result = $this->resolver()->resolve(
            $template,
            $this->qualifiersForNamedGroups(['A', 'B', 'C']),
        );

        $this->assertSame(23, $result->byesCount);
        $this->assertSame($template->byesCount(), $result->byesCount);
    }

    public function test_uses_canonical_order_by_group_name_regardless_of_input_order(): void
    {
        $qualifiers = new Collection([
            $this->qualifier(entryId: 301, groupId: 30, groupName: 'Grupo C', position: 1),
            $this->qualifier(entryId: 303, groupId: 30, groupName: 'Grupo C', position: 3),
            $this->qualifier(entryId: 302, groupId: 30, groupName: 'Grupo C', position: 2),
            $this->qualifier(entryId: 203, groupId: 20, groupName: 'Grupo B', position: 3),
            $this->qualifier(entryId: 101, groupId: 10, groupName: 'Grupo A', position: 1),
            $this->qualifier(entryId: 201, groupId: 20, groupName: 'Grupo B', position: 1),
            $this->qualifier(entryId: 102, groupId: 10, groupName: 'Grupo A', position: 2),
            $this->qualifier(entryId: 202, groupId: 20, groupName: 'Grupo B', position: 2),
            $this->qualifier(entryId: 103, groupId: 10, groupName: 'Grupo A', position: 3),
        ]);

        $result = $this->resolver()->resolve($this->exampleTemplate(), $qualifiers);

        $this->assertSame(101, $result->matches[0]->entry1Id);
        $this->assertSame(303, $result->matches[1]->entry1Id);
        $this->assertSame(203, $result->matches[1]->entry2Id);
    }

    public function test_fails_when_first_place_is_missing(): void
    {
        $qualifiers = $this->qualifiersForNamedGroups(['A', 'B', 'C'])
            ->reject(fn (GroupQualifierData $qualifier): bool => $qualifier->competitionEntryId === 101)
            ->values();

        $this->assertMissingPosition(
            $this->exampleTemplate(),
            $qualifiers,
            'Grupo A',
            1,
        );
    }

    public function test_fails_when_second_place_is_missing(): void
    {
        $template = $this->template(3, 16, [
            $this->match(1, $this->slot(1, 2), null),
        ]);

        $qualifiers = $this->qualifiersForNamedGroups(['A', 'B', 'C'])
            ->reject(fn (GroupQualifierData $qualifier): bool => $qualifier->competitionEntryId === 202)
            ->values();

        $this->assertMissingPosition($template, $qualifiers, 'Grupo B', 2);
    }

    public function test_fails_when_third_place_is_missing(): void
    {
        $qualifiers = $this->qualifiersForNamedGroups(['A', 'B', 'C'])
            ->reject(fn (GroupQualifierData $qualifier): bool => $qualifier->competitionEntryId === 303)
            ->values();

        $this->assertMissingPosition(
            $this->exampleTemplate(),
            $qualifiers,
            'Grupo C',
            3,
        );
    }

    public function test_fails_when_group_index_does_not_exist(): void
    {
        $template = $this->template(2, 16, [
            $this->match(1, $this->slot(5, 1), null),
        ]);

        try {
            $this->resolver()->resolve(
                $template,
                $this->qualifiersForNamedGroups(['A', 'B']),
            );
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('qualified_per_group', $exception->errors());
            $this->assertStringContainsString(
                'la plantilla referencia el grupo en índice 5, que no existe',
                $exception->errors()['qualified_per_group'][0],
            );
        }
    }

    public function test_fails_when_real_group_count_does_not_match_template(): void
    {
        try {
            $this->resolver()->resolve(
                $this->exampleTemplate(),
                $this->qualifiersForNamedGroups(['A', 'B', 'C', 'D']),
            );
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('qualified_per_group', $exception->errors());
            $this->assertStringContainsString(
                'la plantilla requiere 3 grupos y hay 4',
                $exception->errors()['qualified_per_group'][0],
            );
        }
    }

    public function test_fails_when_a_competition_entry_is_resolved_more_than_once(): void
    {
        $template = $this->template(3, 16, [
            $this->match(1, $this->slot(0, 1), null),
            $this->match(2, $this->slot(0, 1), $this->slot(1, 1)),
        ]);

        try {
            $this->resolver()->resolve(
                $template,
                $this->qualifiersForNamedGroups(['A', 'B', 'C']),
            );
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('qualified_per_group', $exception->errors());
            $this->assertStringContainsString(
                'la inscripción 101 aparece más de una vez en la resolución',
                $exception->errors()['qualified_per_group'][0],
            );
        }
    }

    public function test_does_not_mutate_qualifiers_or_template(): void
    {
        $template = $this->exampleTemplate();
        $qualifiers = $this->qualifiersForNamedGroups(['A', 'B', 'C']);
        $templateFingerprint = $this->templateFingerprint($template);
        $qualifierFingerprints = $qualifiers->map(
            fn (GroupQualifierData $qualifier): array => $this->qualifierFingerprint($qualifier),
        )->all();
        $originalEntryOrder = $qualifiers->pluck('competitionEntryId')->all();
        $originalMatches = $template->matches;

        $this->resolver()->resolve($template, $qualifiers);

        $this->assertSame($originalMatches, $template->matches);
        $this->assertSame($templateFingerprint, $this->templateFingerprint($template));
        $this->assertSame($originalEntryOrder, $qualifiers->pluck('competitionEntryId')->all());
        $this->assertSame(
            $qualifierFingerprints,
            $qualifiers->map(
                fn (GroupQualifierData $qualifier): array => $this->qualifierFingerprint($qualifier),
            )->all(),
        );
    }

    public function test_resolves_complete_three_group_official_draw(): void
    {
        $result = $this->resolver()->resolve(
            GroupKnockoutDrawTemplateCatalog::forGroupCount(3),
            $this->qualifiersForNamedGroups(['A', 'B', 'C']),
        );

        $this->assertSame(16, $result->bracketSize);
        $this->assertSame(7, $result->byesCount);
        $this->assertSame(BracketSupport::roundLabelFor(16), $result->firstRoundLabel);
        $this->assertResolvedMatches($result, [
            [1, 101, null, true],
            [2, 303, 203, false],
            [3, 202, null, true],
            [4, 301, null, true],
            [5, 302, null, true],
            [6, 102, null, true],
            [7, 103, null, true],
            [8, 201, null, true],
        ]);
    }

    public function test_resolves_complete_five_group_official_draw(): void
    {
        $result = $this->resolver()->resolve(
            GroupKnockoutDrawTemplateCatalog::forGroupCount(5),
            $this->qualifiersForNamedGroups(['A', 'B', 'C', 'D', 'E']),
        );

        $this->assertSame(16, $result->bracketSize);
        $this->assertSame(1, $result->byesCount);
        $this->assertSame(BracketSupport::roundLabelFor(16), $result->firstRoundLabel);
        $this->assertResolvedMatches($result, [
            [1, 101, null, true],
            [2, 302, 203, false],
            [3, 501, 202, false],
            [4, 103, 401, false],
            [5, 301, 503, false],
            [6, 102, 402, false],
            [7, 502, 403, false],
            [8, 303, 201, false],
        ]);
    }

    public function test_resolves_complete_nine_group_official_draw(): void
    {
        $result = $this->resolver()->resolve(
            GroupKnockoutDrawTemplateCatalog::forGroupCount(9),
            $this->qualifiersForNamedGroups(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I']),
        );

        $this->assertSame(32, $result->bracketSize);
        $this->assertSame(5, $result->byesCount);
        $this->assertSame(BracketSupport::roundLabelFor(32), $result->firstRoundLabel);
        $this->assertResolvedMatches($result, [
            [1, 101, null, true],
            [2, 702, 503, false],
            [3, 901, 403, false],
            [4, 801, 203, false],
            [5, 501, null, true],
            [6, 202, 302, false],
            [7, 602, 103, false],
            [8, 401, null, true],
            [9, 301, null, true],
            [10, 402, 903, false],
            [11, 102, 502, false],
            [12, 601, 803, false],
            [13, 701, 303, false],
            [14, 802, 902, false],
            [15, 603, 703, false],
            [16, 201, null, true],
        ]);
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int|null, 3: bool}>  $expected
     */
    private function assertResolvedMatches(GroupKnockoutDrawResult $result, array $expected): void
    {
        $this->assertCount(count($expected), $result->matches);

        foreach ($expected as $index => [$bracketMatch, $entry1Id, $entry2Id, $isBye]) {
            $match = $result->matches[$index];
            $this->assertInstanceOf(BracketDrawMatchData::class, $match);
            $this->assertSame($bracketMatch, $match->bracketMatch, sprintf('partido %d', $index + 1));
            $this->assertSame($entry1Id, $match->entry1Id, sprintf('partido %d entry1', $bracketMatch));
            $this->assertSame($entry2Id, $match->entry2Id, sprintf('partido %d entry2', $bracketMatch));
            $this->assertSame($isBye, $match->isBye, sprintf('partido %d isBye', $bracketMatch));
        }
    }

    /**
     * @param  Collection<int, GroupQualifierData>  $qualifiers
     */
    private function assertMissingPosition(
        GroupKnockoutDrawTemplate $template,
        Collection $qualifiers,
        string $groupName,
        int $position,
    ): void {
        try {
            $this->resolver()->resolve($template, $qualifiers);
            $this->fail('Se esperaba ValidationException');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('qualified_per_group', $exception->errors());
            $this->assertSame(
                sprintf(
                    'No se puede generar la llave: %s no dispone del %d.º clasificado requerido por la plantilla.',
                    $groupName,
                    $position,
                ),
                $exception->errors()['qualified_per_group'][0],
            );
        }
    }

    private function resolver(): GroupKnockoutDrawTemplateResolver
    {
        return new GroupKnockoutDrawTemplateResolver;
    }

    /**
     * Match 1: 1A vs BYE, Match 2: 3C vs 3B.
     */
    private function exampleTemplate(): GroupKnockoutDrawTemplate
    {
        return $this->template(3, 16, [
            $this->match(1, $this->slot(0, 1), null),
            $this->match(2, $this->slot(2, 3), $this->slot(1, 3)),
        ]);
    }

    /**
     * @param  list<GroupKnockoutTemplateMatch>  $matches
     */
    private function template(int $groupCount, int $bracketSize, array $matches): GroupKnockoutDrawTemplate
    {
        return new GroupKnockoutDrawTemplate(
            groupCount: $groupCount,
            qualifiedPerGroup: 3,
            bracketSize: $bracketSize,
            matches: $matches,
        );
    }

    private function match(
        int $bracketMatch,
        GroupKnockoutTemplateSlot $side1,
        ?GroupKnockoutTemplateSlot $side2,
    ): GroupKnockoutTemplateMatch {
        return new GroupKnockoutTemplateMatch(
            bracketMatch: $bracketMatch,
            side1: $side1,
            side2: $side2,
        );
    }

    private function slot(int $groupIndex, int $groupPosition): GroupKnockoutTemplateSlot
    {
        return new GroupKnockoutTemplateSlot(
            groupIndex: $groupIndex,
            groupPosition: $groupPosition,
        );
    }

    /**
     * @param  list<string>  $groupNames
     * @return Collection<int, GroupQualifierData>
     */
    private function qualifiersForNamedGroups(array $groupNames): Collection
    {
        $qualifiers = new Collection;

        foreach ($groupNames as $groupIndex => $groupName) {
            $groupId = ($groupIndex + 1) * 100;

            for ($position = 1; $position <= 3; $position++) {
                $qualifiers->push($this->qualifier(
                    entryId: $groupId + $position,
                    groupId: $groupId,
                    groupName: 'Grupo '.$groupName,
                    position: $position,
                ));
            }
        }

        return $qualifiers;
    }

    private function qualifier(
        int $entryId,
        int $groupId,
        string $groupName,
        int $position,
    ): GroupQualifierData {
        return new GroupQualifierData(
            competitionEntryId: $entryId,
            displayName: sprintf('Jugador %d', $entryId),
            members: [[
                'id' => $entryId,
                'first_name' => 'Jugador',
                'last_name' => (string) $entryId,
                'nickname' => null,
            ]],
            playerId: $entryId,
            playerName: sprintf('Jugador %d', $entryId),
            groupId: $groupId,
            groupName: $groupName,
            groupPosition: $position,
            won: 0,
            lost: 0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function templateFingerprint(GroupKnockoutDrawTemplate $template): array
    {
        return [
            'groupCount' => $template->groupCount,
            'qualifiedPerGroup' => $template->qualifiedPerGroup,
            'bracketSize' => $template->bracketSize,
            'matches' => array_map(
                fn (GroupKnockoutTemplateMatch $match): array => [
                    'bracketMatch' => $match->bracketMatch,
                    'side1' => [$match->side1->groupIndex, $match->side1->groupPosition],
                    'side2' => $match->side2 === null
                        ? null
                        : [$match->side2->groupIndex, $match->side2->groupPosition],
                ],
                $template->matches,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function qualifierFingerprint(GroupQualifierData $qualifier): array
    {
        return [
            'competitionEntryId' => $qualifier->competitionEntryId,
            'displayName' => $qualifier->displayName,
            'members' => $qualifier->members,
            'playerId' => $qualifier->playerId,
            'playerName' => $qualifier->playerName,
            'groupId' => $qualifier->groupId,
            'groupName' => $qualifier->groupName,
            'groupPosition' => $qualifier->groupPosition,
            'won' => $qualifier->won,
            'lost' => $qualifier->lost,
        ];
    }
}
