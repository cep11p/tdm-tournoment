<?php

namespace Tests\Feature\Bracket;

use App\Enums\GameStatus;
use App\Models\Bracket;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Group;
use App\Models\Player;
use App\Support\Bracket\BracketSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class GroupKnockoutDrawTest extends TestCase
{
    public function test_builds_two_group_draw_as_a1_vs_b2_and_b1_vs_a2(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $response = $context->createBracket($setup['competition']);

        $response->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $this->assertCount(2, $semifinals);
        $this->assertSame('Semifinal', $semifinals[0]->round);

        $this->assertSame($setup['playerOne']->id, $semifinals[0]->singlesPlayer1Id());
        $this->assertSame($setup['playerFour']->id, $semifinals[0]->singlesPlayer2Id());
        $this->assertSame($setup['playerThree']->id, $semifinals[1]->singlesPlayer1Id());
        $this->assertSame($setup['playerTwo']->id, $semifinals[1]->singlesPlayer2Id());
    }

    public function test_builds_four_group_draw_without_same_group_first_round_matches(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createFourGroupPhase($context);

        $response = $context->createBracket($setup['competition']);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 8)
            ->assertJsonPath('data.byes_count', 0);

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $quarterfinals = $context->bracketGamesForRound($bracket, 1);

        $this->assertCount(4, $quarterfinals);
        $this->assertSame('Cuartos de final', $quarterfinals[0]->round);

        $groupByPlayerId = [
            $setup['groupAFirst']->id => $setup['groupA']->id,
            $setup['groupASecond']->id => $setup['groupA']->id,
            $setup['groupBFirst']->id => $setup['groupB']->id,
            $setup['groupBSecond']->id => $setup['groupB']->id,
            $setup['groupCFirst']->id => $setup['groupC']->id,
            $setup['groupCSecond']->id => $setup['groupC']->id,
            $setup['groupDFirst']->id => $setup['groupD']->id,
            $setup['groupDSecond']->id => $setup['groupD']->id,
        ];

        foreach ($quarterfinals as $game) {
            $this->assertNotSame(
                $groupByPlayerId[$game->singlesPlayer1Id()],
                $groupByPlayerId[$game->singlesPlayer2Id()],
            );
        }

        $this->assertSame($setup['groupAFirst']->id, $quarterfinals[0]->singlesPlayer1Id());
        $this->assertSame($setup['groupDSecond']->id, $quarterfinals[0]->singlesPlayer2Id());
        $this->assertSame($setup['groupBFirst']->id, $quarterfinals[1]->singlesPlayer1Id());
        $this->assertSame($setup['groupCSecond']->id, $quarterfinals[1]->singlesPlayer2Id());
        $this->assertSame($setup['groupCFirst']->id, $quarterfinals[2]->singlesPlayer1Id());
        $this->assertSame($setup['groupBSecond']->id, $quarterfinals[2]->singlesPlayer2Id());
        $this->assertSame($setup['groupDFirst']->id, $quarterfinals[3]->singlesPlayer1Id());
        $this->assertSame($setup['groupASecond']->id, $quarterfinals[3]->singlesPlayer2Id());
    }

    public function test_group_knockout_q2_does_not_use_global_record_for_byes_or_seeding(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(5);
        $context->registerPlayers($competition, $players);

        [$groupAFirst, $groupASecond, $groupAThird, $groupBFirst, $groupBSecond] = $players;

        $groupA = $context->createGroupWithPlayers(
            $competition,
            [$groupAFirst, $groupASecond, $groupAThird],
            'Grupo A',
        );
        $groupB = $context->createGroupWithPlayers(
            $competition,
            [$groupBFirst, $groupBSecond],
            'Grupo B',
        );

        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();

        $this->finishGroupRoundRobinWithRankOrder($context, $groupA->id, [
            $groupAFirst,
            $groupASecond,
            $groupAThird,
        ]);
        $this->finishGroupRoundRobinWithRankOrder($context, $groupB->id, [
            $groupBSecond,
            $groupBFirst,
        ]);

        $response = $context->createBracket($competition);
        $response->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $this->assertSame($groupAFirst->id, $semifinals[0]->singlesPlayer1Id());
        $this->assertSame($groupBFirst->id, $semifinals[0]->singlesPlayer2Id());
        $this->assertSame($groupBSecond->id, $semifinals[1]->singlesPlayer1Id());
        $this->assertSame($groupASecond->id, $semifinals[1]->singlesPlayer2Id());
    }

    public function test_rejects_q2_draw_when_total_qualifiers_is_not_power_of_two(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $groups = [
            $context->createGroupWithPlayers($competition, array_slice($players, 0, 2), 'Grupo A'),
            $context->createGroupWithPlayers($competition, array_slice($players, 2, 2), 'Grupo B'),
            $context->createGroupWithPlayers($competition, array_slice($players, 4, 2), 'Grupo C'),
        ];

        foreach ($groups as $index => $group) {
            $context->generateRoundRobin($group)->assertCreated();

            $game = Game::query()->where('group_id', $group->id)->sole();
            $context->finishGame($game, $players[$index * 2])->assertOk();
        }

        $response = $context->createBracket($competition);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['qualified_per_group']);

        $this->assertDatabaseCount('brackets', 0);
    }

    public function test_direct_knockout_keeps_existing_legacy_behavior(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1)->sortBy('bracket_match')->values();

        $this->assertSame($players[0]->id, $semifinals[0]->singlesPlayer1Id());
        $this->assertSame($players[3]->id, $semifinals[0]->singlesPlayer2Id());
        $this->assertSame($players[1]->id, $semifinals[1]->singlesPlayer1Id());
        $this->assertSame($players[2]->id, $semifinals[1]->singlesPlayer2Id());
    }

    public function test_can_advance_round_after_group_aware_q2_draw(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1);

        $context->finishGame($semifinals[0], $setup['playerOne'])->assertOk();
        $context->finishGame($semifinals[1], $setup['playerThree'])->assertOk();

        $response = $context->generateBracketNextRound($bracket);

        $response->assertCreated();

        $final = $context->bracketGamesForRound($bracket, 2);

        $this->assertCount(1, $final);
        $this->assertSame('Final', $final[0]->round);
        $this->assertSame($setup['playerOne']->id, $final[0]->singlesPlayer1Id());
        $this->assertSame($setup['playerThree']->id, $final[0]->singlesPlayer2Id());
    }

    public function test_creates_two_group_q3_bracket_with_padding_byes_for_six_qualifiers(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(7);
        $context->registerPlayers($competition, $players);
        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        [
            $groupAFirst,
            $groupASecond,
            $groupAThird,
            $groupAFourth,
            $groupBFirst,
            $groupBSecond,
            $groupBThird,
        ] = $players;

        $groupA = $context->createGroupWithPlayers(
            $competition,
            [$groupAFirst, $groupASecond, $groupAThird, $groupAFourth],
            'Grupo A',
        );
        $groupB = $context->createGroupWithPlayers(
            $competition,
            [$groupBFirst, $groupBSecond, $groupBThird],
            'Grupo B',
        );

        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();

        $this->finishGroupRoundRobinWithRankOrder($context, $groupA->id, [
            $groupAFirst,
            $groupASecond,
            $groupAThird,
            $groupAFourth,
        ]);
        $this->finishGroupRoundRobinWithRankOrder($context, $groupB->id, [
            $groupBFirst,
            $groupBSecond,
            $groupBThird,
        ]);

        $response = $context->createBracket($competition);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 8)
            ->assertJsonPath('data.byes_count', 2)
            ->assertJsonCount(4, 'data.games');

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $firstRound = $context->bracketGamesForRound($bracket, 1);

        $this->assertCount(4, $firstRound);
        $this->assertSame('Cuartos de final', $firstRound[0]->round);
        $this->assertNotSame(BracketSupport::PLAY_IN_ROUND_LABEL, $firstRound[0]->round);

        $byeGames = $firstRound->filter(fn (Game $game): bool => $game->is_bye)->values();

        $this->assertCount(2, $byeGames);
        $this->assertContains($groupAFirst->id, $byeGames->map(fn ($game) => $game->singlesPlayer1Id())->all());
        $this->assertContains($groupBFirst->id, $byeGames->map(fn ($game) => $game->singlesPlayer1Id())->all());
    }

    public function test_two_group_q3_generates_semifinals_after_real_games_finished(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(7);
        $context->registerPlayers($competition, $players);
        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        [
            $groupAFirst,
            $groupASecond,
            $groupAThird,
            $groupAFourth,
            $groupBFirst,
            $groupBSecond,
            $groupBThird,
        ] = $players;

        $groupA = $context->createGroupWithPlayers(
            $competition,
            [$groupAFirst, $groupASecond, $groupAThird, $groupAFourth],
            'Grupo A',
        );
        $groupB = $context->createGroupWithPlayers(
            $competition,
            [$groupBFirst, $groupBSecond, $groupBThird],
            'Grupo B',
        );

        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();

        $this->finishGroupRoundRobinWithRankOrder($context, $groupA->id, [
            $groupAFirst,
            $groupASecond,
            $groupAThird,
            $groupAFourth,
        ]);
        $this->finishGroupRoundRobinWithRankOrder($context, $groupB->id, [
            $groupBFirst,
            $groupBSecond,
            $groupBThird,
        ]);

        $response = $context->createBracket($competition);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', 8)
            ->assertJsonPath('data.byes_count', 2)
            ->assertJsonCount(4, 'data.games');

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $firstRound = $context->bracketGamesForRound($bracket, 1)->sortBy('bracket_match')->values();

        $this->assertCount(4, $firstRound);
        $this->assertSame('Cuartos de final', $firstRound[0]->round);

        $byeGames = $firstRound->filter(fn (Game $game): bool => $game->is_bye)->values();
        $realGames = $firstRound->reject(fn (Game $game): bool => $game->is_bye)->values();

        $this->assertCount(2, $byeGames);
        $this->assertCount(2, $realGames);

        foreach ($byeGames as $byeGame) {
            $this->assertSame(GameStatus::Finished, $byeGame->status);
            $this->assertSame($byeGame->singlesPlayer1Id(), $byeGame->singlesWinnerId());
            $this->assertNull($byeGame->singlesPlayer2Id());
        }

        $this->assertContains($groupAFirst->id, $byeGames->map(fn ($game) => $game->singlesPlayer1Id())->all());
        $this->assertContains($groupBFirst->id, $byeGames->map(fn ($game) => $game->singlesPlayer1Id())->all());

        foreach ($realGames as $realGame) {
            $this->assertSame(GameStatus::Pending, $realGame->status);
            $this->assertNull($realGame->singlesWinnerId());
        }

        $a2VsB3 = $realGames->first(
            fn (Game $game): bool => (
                (int) $game->singlesPlayer1Id() === $groupASecond->id
                && (int) $game->singlesPlayer2Id() === $groupBThird->id
            ) || (
                (int) $game->singlesPlayer1Id() === $groupBThird->id
                && (int) $game->singlesPlayer2Id() === $groupASecond->id
            ),
        );
        $b2VsA3 = $realGames->first(
            fn (Game $game): bool => (
                (int) $game->singlesPlayer1Id() === $groupBSecond->id
                && (int) $game->singlesPlayer2Id() === $groupAThird->id
            ) || (
                (int) $game->singlesPlayer1Id() === $groupAThird->id
                && (int) $game->singlesPlayer2Id() === $groupBSecond->id
            ),
        );

        $this->assertNotNull($a2VsB3);
        $this->assertNotNull($b2VsA3);

        $context->finishGame($a2VsB3, $groupASecond)->assertOk();
        $context->finishGame($b2VsA3, $groupBSecond)->assertOk();

        $response = $context->generateBracketNextRound($bracket);

        $response->assertCreated();

        $semifinals = $context->bracketGamesForRound($bracket->fresh(), 2)->sortBy('bracket_match')->values();

        $this->assertCount(2, $semifinals);
        $this->assertSame('Semifinal', $semifinals[0]->round);
        $this->assertSame('Semifinal', $semifinals[1]->round);

        $semifinalPlayerIds = $semifinals
            ->flatMap(fn (Game $game): array => [(int) $game->singlesPlayer1Id(), (int) $game->singlesPlayer2Id()])
            ->all();

        $this->assertContains($groupAFirst->id, $semifinalPlayerIds);
        $this->assertContains($groupBFirst->id, $semifinalPlayerIds);
        $this->assertContains($groupASecond->id, $semifinalPlayerIds);
        $this->assertContains($groupBSecond->id, $semifinalPlayerIds);

        foreach ($semifinals as $semifinal) {
            $this->assertFalse($semifinal->is_bye);
            $this->assertSame(GameStatus::Pending, $semifinal->status);
            $this->assertNull($semifinal->singlesWinnerId());
        }

        $this->assertSame($groupAFirst->id, $semifinals[0]->singlesPlayer1Id());
        $this->assertSame($groupBFirst->id, $semifinals[0]->singlesPlayer2Id());
        $this->assertSame($groupASecond->id, $semifinals[1]->singlesPlayer1Id());
        $this->assertSame($groupBSecond->id, $semifinals[1]->singlesPlayer2Id());
    }

    /**
     * @return array<string, array{
     *     0: int,
     *     1: int,
     *     2: int,
     *     3: string,
     *     4: list<string>,
     *     5: list<array{0: int, 1: string, 2: string|null}>
     * }>
     */
    public static function officialQ3TemplateProvider(): array
    {
        return [
            '3 groups' => [3, 16, 7, '8vos de final', ['A', 'B', 'C'], [
                [1, 'A1', null],
                [2, 'C3', 'B3'],
                [3, 'B2', null],
                [4, 'C1', null],
                [5, 'C2', null],
                [6, 'A2', null],
                [7, 'A3', null],
                [8, 'B1', null],
            ]],
            '4 groups' => [4, 16, 4, '8vos de final', ['A', 'B', 'C', 'D'], [
                [1, 'A1', null],
                [2, 'C2', 'B3'],
                [3, 'C3', 'B2'],
                [4, 'D1', null],
                [5, 'C1', null],
                [6, 'A2', 'D3'],
                [7, 'A3', 'D2'],
                [8, 'B1', null],
            ]],
            '5 groups' => [5, 16, 1, '8vos de final', ['A', 'B', 'C', 'D', 'E'], [
                [1, 'A1', null],
                [2, 'C2', 'B3'],
                [3, 'E1', 'B2'],
                [4, 'A3', 'D1'],
                [5, 'C1', 'E3'],
                [6, 'A2', 'D2'],
                [7, 'E2', 'D3'],
                [8, 'C3', 'B1'],
            ]],
            '6 groups' => [6, 32, 14, '16avos de final', ['A', 'B', 'C', 'D', 'E', 'F'], [
                [1, 'A1', null],
                [2, 'D3', 'E3'],
                [3, 'F2', null],
                [4, 'C2', null],
                [5, 'E1', null],
                [6, 'B2', null],
                [7, 'A3', null],
                [8, 'D1', null],
                [9, 'C1', null],
                [10, 'B3', null],
                [11, 'A2', null],
                [12, 'F1', null],
                [13, 'D2', null],
                [14, 'E2', null],
                [15, 'C3', 'F3'],
                [16, 'B1', null],
            ]],
            '7 groups' => [7, 32, 11, '16avos de final', ['A', 'B', 'C', 'D', 'E', 'F', 'G'], [
                [1, 'A1', null],
                [2, 'D3', 'E3'],
                [3, 'F2', null],
                [4, 'G2', null],
                [5, 'E1', null],
                [6, 'B2', 'G3'],
                [7, 'C2', 'A3'],
                [8, 'D1', null],
                [9, 'C1', null],
                [10, 'E2', 'B3'],
                [11, 'A2', null],
                [12, 'F1', null],
                [13, 'G1', null],
                [14, 'D2', null],
                [15, 'F3', 'C3'],
                [16, 'B1', null],
            ]],
            '8 groups' => [8, 32, 8, '16avos de final', ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'], [
                [1, 'A1', null],
                [2, 'G2', 'D3'],
                [3, 'F2', 'E3'],
                [4, 'H1', null],
                [5, 'E1', null],
                [6, 'B2', 'H3'],
                [7, 'C2', 'A3'],
                [8, 'D1', null],
                [9, 'C1', null],
                [10, 'E2', 'B3'],
                [11, 'A2', 'G3'],
                [12, 'F1', null],
                [13, 'G1', null],
                [14, 'D2', 'C3'],
                [15, 'H2', 'F3'],
                [16, 'B1', null],
            ]],
            '9 groups' => [9, 32, 5, '16avos de final', ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'], [
                [1, 'A1', null],
                [2, 'G2', 'E3'],
                [3, 'I1', 'D3'],
                [4, 'H1', 'B3'],
                [5, 'E1', null],
                [6, 'B2', 'C2'],
                [7, 'F2', 'A3'],
                [8, 'D1', null],
                [9, 'C1', null],
                [10, 'D2', 'I3'],
                [11, 'A2', 'E2'],
                [12, 'F1', 'H3'],
                [13, 'G1', 'C3'],
                [14, 'H2', 'I2'],
                [15, 'F3', 'G3'],
                [16, 'B1', null],
            ]],
        ];
    }

    /**
     * @param  list<string>  $groupLetters
     * @param  list<array{0: int, 1: string, 2: string|null}>  $expectedMatches
     */
    #[DataProvider('officialQ3TemplateProvider')]
    public function test_creates_official_q3_bracket_from_template(
        int $groupCount,
        int $bracketSize,
        int $byesCount,
        string $firstRoundLabel,
        array $groupLetters,
        array $expectedMatches,
    ): void {
        $context = $this->tournamentContext();
        $setup = $this->createOfficialQ3Phase($context, $groupLetters);
        $matchCount = (int) ($bracketSize / 2);

        $response = $context->createBracket($setup['competition']);

        $response
            ->assertCreated()
            ->assertJsonPath('data.bracket_size', $bracketSize)
            ->assertJsonPath('data.byes_count', $byesCount)
            ->assertJsonCount($matchCount, 'data.games');

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $firstRound = $context->bracketGamesForRound($bracket, 1);

        $this->assertCount($matchCount, $firstRound);
        $this->assertSame($firstRoundLabel, $firstRound[0]->round);
        $this->assertNotSame(BracketSupport::PLAY_IN_ROUND_LABEL, $firstRound[0]->round);
        $this->assertSame(
            range(1, $matchCount),
            $firstRound->pluck('bracket_match')->map(fn ($match): int => (int) $match)->all(),
        );

        $seenEntryIds = [];

        foreach ($expectedMatches as $index => [$bracketMatch, $side1Slot, $side2Slot]) {
            $game = $firstRound[$index];
            $side1EntryId = $setup['entryIds'][$side1Slot];
            $side2EntryId = $side2Slot === null ? null : $setup['entryIds'][$side2Slot];

            $this->assertSame($bracketMatch, (int) $game->bracket_match);
            $this->assertSame($firstRoundLabel, $game->round);
            $this->assertSame($side1EntryId, (int) $game->entry1_id);
            $this->assertSame($side2EntryId, $game->entry2_id === null ? null : (int) $game->entry2_id);
            $this->assertSame($side2Slot === null, $game->is_bye);

            $this->assertNotContains($side1EntryId, $seenEntryIds);
            $seenEntryIds[] = $side1EntryId;

            if ($side2EntryId === null) {
                $this->assertSame(GameStatus::Finished, $game->status);
                $this->assertSame($side1EntryId, (int) $game->winner_entry_id);
                $this->assertNull($game->entry2_id);

                continue;
            }

            $this->assertNotContains($side2EntryId, $seenEntryIds);
            $seenEntryIds[] = $side2EntryId;
            $this->assertSame(GameStatus::Pending, $game->status);
            $this->assertNull($game->winner_entry_id);
        }

        $this->assertCount($groupCount * 3, $seenEntryIds);
        $this->assertCount($groupCount * 3, array_unique($seenEntryIds));
        $this->assertEqualsCanonicalizing(array_values($setup['entryIds']), $seenEntryIds);
        $this->assertCount($byesCount, $firstRound->filter(fn (Game $game): bool => $game->is_bye));
    }

    /**
     * @return array<string, array{
     *     0: list<string>,
     *     1: string,
     *     2: list<array{0: int, 1: string, 2: string}>
     * }>
     */
    public static function officialQ3NextRoundProvider(): array
    {
        return [
            '3 groups' => [['A', 'B', 'C'], 'Cuartos de final', [
                [1, 'A1', 'C3'],
                [2, 'B2', 'C1'],
                [3, 'C2', 'A2'],
                [4, 'A3', 'B1'],
            ]],
            '4 groups' => [['A', 'B', 'C', 'D'], 'Cuartos de final', [
                [1, 'A1', 'C2'],
                [2, 'C3', 'D1'],
                [3, 'C1', 'A2'],
                [4, 'A3', 'B1'],
            ]],
            '9 groups' => [['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'], '8vos de final', [
                [1, 'A1', 'G2'],
                [2, 'I1', 'H1'],
                [3, 'E1', 'B2'],
                [4, 'F2', 'D1'],
                [5, 'C1', 'D2'],
                [6, 'A2', 'F1'],
                [7, 'G1', 'H2'],
                [8, 'F3', 'B1'],
            ]],
        ];
    }

    /**
     * @param  list<string>  $groupLetters
     * @param  list<array{0: int, 1: string, 2: string}>  $expectedNextRound
     */
    #[DataProvider('officialQ3NextRoundProvider')]
    public function test_advances_official_q3_next_round_from_template_winners(
        array $groupLetters,
        string $nextRoundLabel,
        array $expectedNextRound,
    ): void {
        $context = $this->tournamentContext();
        $setup = $this->createOfficialQ3Phase($context, $groupLetters);

        $context->createBracket($setup['competition'])->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $firstRound = $context->bracketGamesForRound($bracket, 1);

        foreach ($firstRound as $game) {
            if ($game->is_bye) {
                $this->assertSame(GameStatus::Finished, $game->status);
                $this->assertSame((int) $game->entry1_id, (int) $game->winner_entry_id);

                continue;
            }

            $context->finishGame($game, $game->singlesPlayer1())->assertOk();
        }

        $response = $context->generateBracketNextRound($bracket);
        $response->assertCreated();

        $secondRound = $context->bracketGamesForRound($bracket->fresh(), 2);

        $this->assertCount(count($expectedNextRound), $secondRound);
        $this->assertSame($nextRoundLabel, $secondRound[0]->round);
        $this->assertSame(
            range(1, count($expectedNextRound)),
            $secondRound->pluck('bracket_match')->map(fn ($match): int => (int) $match)->all(),
        );

        foreach ($expectedNextRound as $index => [$bracketMatch, $side1Slot, $side2Slot]) {
            $game = $secondRound[$index];

            $this->assertSame($bracketMatch, (int) $game->bracket_match);
            $this->assertSame($setup['entryIds'][$side1Slot], (int) $game->entry1_id);
            $this->assertSame($setup['entryIds'][$side2Slot], (int) $game->entry2_id);
            $this->assertFalse($game->is_bye);
            $this->assertSame(GameStatus::Pending, $game->status);
            $this->assertNull($game->winner_entry_id);
        }
    }

    public function test_q3_four_group_official_template_does_not_assign_bye_to_second_place(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createOfficialQ3Phase($context, ['A', 'B', 'C', 'D']);

        $response = $context->createBracket($setup['competition']);
        $response->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $byeGames = $context->bracketGamesForRound($bracket, 1)
            ->filter(fn (Game $game): bool => $game->is_bye)
            ->values();

        $secondPlaceIds = [
            $setup['players']['A2']->id,
            $setup['players']['B2']->id,
            $setup['players']['C2']->id,
            $setup['players']['D2']->id,
        ];

        foreach ($byeGames as $byeGame) {
            $this->assertNotContains($byeGame->singlesPlayer1Id(), $secondPlaceIds);
            $this->assertNotContains($byeGame->singlesWinnerId(), $secondPlaceIds);
        }
    }

    public function test_direct_knockout_keeps_existing_behavior_after_q3_draw_change(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1)->sortBy('bracket_match')->values();

        $this->assertSame($players[0]->id, $semifinals[0]->singlesPlayer1Id());
        $this->assertSame($players[3]->id, $semifinals[0]->singlesPlayer2Id());
        $this->assertSame($players[1]->id, $semifinals[1]->singlesPlayer1Id());
        $this->assertSame($players[2]->id, $semifinals[1]->singlesPlayer2Id());
    }

    /**
     * @param  list<string>  $groupLetters
     * @return array{
     *     competition: Competition,
     *     players: array<string, Player>,
     *     entryIds: array<string, int>
     * }
     */
    private function createOfficialQ3Phase(TournamentTestContext $context, array $groupLetters): array
    {
        $competition = $context->createCompetition();
        $players = $context->createPlayers(count($groupLetters) * 3);
        $context->registerPlayers($competition, $players);
        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        $playersBySlot = [];
        $entryIds = [];
        $playerIndex = 0;

        foreach ($groupLetters as $letter) {
            $groupPlayers = [
                $players[$playerIndex],
                $players[$playerIndex + 1],
                $players[$playerIndex + 2],
            ];
            $playerIndex += 3;

            $group = $context->createGroupWithPlayers(
                $competition,
                $groupPlayers,
                'Grupo '.$letter,
            );
            $context->generateRoundRobin($group)->assertCreated();
            $this->finishGroupRoundRobinWithRankOrder($context, $group->id, $groupPlayers);

            foreach ([1, 2, 3] as $position) {
                $slot = $letter.$position;
                $player = $groupPlayers[$position - 1];
                $playersBySlot[$slot] = $player;
                $entryIds[$slot] = $context->entryIdFor($competition, $player);
            }
        }

        return [
            'competition' => $competition,
            'players' => $playersBySlot,
            'entryIds' => $entryIds,
        ];
    }

    /**
     * @return array{
     *     competition: Competition,
     *     groupA: Group,
     *     groupB: Group,
     *     groupC: Group,
     *     groupD: Group,
     *     groupAFirst: Player,
     *     groupASecond: Player,
     *     groupBFirst: Player,
     *     groupBSecond: Player,
     *     groupCFirst: Player,
     *     groupCSecond: Player,
     *     groupDFirst: Player,
     *     groupDSecond: Player,
     * }
     */
    private function createFourGroupPhase(TournamentTestContext $context): array
    {
        $competition = $context->createCompetition();
        $players = $context->createPlayers(8);
        $context->registerPlayers($competition, $players);

        [
            $groupAFirst,
            $groupASecond,
            $groupBFirst,
            $groupBSecond,
            $groupCFirst,
            $groupCSecond,
            $groupDFirst,
            $groupDSecond,
        ] = $players;

        $groupA = $context->createGroupWithPlayers($competition, [$groupAFirst, $groupASecond], 'Grupo A');
        $groupB = $context->createGroupWithPlayers($competition, [$groupBFirst, $groupBSecond], 'Grupo B');
        $groupC = $context->createGroupWithPlayers($competition, [$groupCFirst, $groupCSecond], 'Grupo C');
        $groupD = $context->createGroupWithPlayers($competition, [$groupDFirst, $groupDSecond], 'Grupo D');

        foreach ([
            [$groupA, $groupAFirst],
            [$groupB, $groupBFirst],
            [$groupC, $groupCFirst],
            [$groupD, $groupDFirst],
        ] as [$group, $winner]) {
            $context->generateRoundRobin($group)->assertCreated();

            $game = Game::query()->where('group_id', $group->id)->sole();
            $context->finishGame($game, $winner)->assertOk();
        }

        return [
            'competition' => $competition,
            'groupA' => $groupA,
            'groupB' => $groupB,
            'groupC' => $groupC,
            'groupD' => $groupD,
            'groupAFirst' => $groupAFirst,
            'groupASecond' => $groupASecond,
            'groupBFirst' => $groupBFirst,
            'groupBSecond' => $groupBSecond,
            'groupCFirst' => $groupCFirst,
            'groupCSecond' => $groupCSecond,
            'groupDFirst' => $groupDFirst,
            'groupDSecond' => $groupDSecond,
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
                        (int) $candidate->singlesPlayer1Id() === $left->id && (int) $candidate->singlesPlayer2Id() === $right->id
                    ) || (
                        (int) $candidate->singlesPlayer1Id() === $right->id && (int) $candidate->singlesPlayer2Id() === $left->id
                    )
                );

                $this->assertNotNull($game);
                $context->finishGame($game, $winner)->assertOk();
            }
        }
    }
}
