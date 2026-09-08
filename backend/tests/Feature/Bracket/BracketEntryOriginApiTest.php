<?php

namespace Tests\Feature\Bracket;

use App\Enums\TeamTieModality;
use App\Models\Bracket;
use App\Models\BracketEntryOrigin;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\Group;
use App\Models\Player;
use App\Models\TeamTie;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class BracketEntryOriginApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders($this->authHeaders(['organizer']));
    }

    public function test_singles_bracket_sides_include_group_origin_from_snapshot(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();

        $createResponse = $context->createBracket($setup['competition'])->assertCreated();
        $this->assertBracketGamesHaveOrigins($createResponse->json('data.games'));

        $showResponse = $context->showBracket($setup['competition'])->assertOk();
        $this->assertBracketGamesHaveOrigins($showResponse->json('data.games'));

        $firstSide = $showResponse->json('data.games.0.side1');
        $this->assertSame(['group_id', 'group_name', 'position'], array_keys($firstSide['group_origin']));
        $this->assertArrayNotHasKey('group_position', $firstSide['group_origin']);
        $this->assertMatchesRegularExpression(
            '/^[12]\.º · Grupo [AB]$/',
            sprintf('%d.º · %s', $firstSide['group_origin']['position'], $firstSide['group_origin']['group_name']),
        );
    }

    public function test_doubles_bracket_sides_include_group_origin(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition();
        $players = $context->createPlayers(8);
        $entries = $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
            [$players[4], $players[5]],
            [$players[6], $players[7]],
        ]);

        $groupA = $context->createGroup($competition, 'Grupo A');
        $groupB = $context->createGroup($competition, 'Grupo B');

        $context->assignEntryToGroupViaApi($groupA, $entries[0])->assertCreated();
        $context->assignEntryToGroupViaApi($groupA, $entries[1])->assertCreated();
        $context->assignEntryToGroupViaApi($groupB, $entries[2])->assertCreated();
        $context->assignEntryToGroupViaApi($groupB, $entries[3])->assertCreated();

        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();

        $gamesA = Game::query()->where('group_id', $groupA->id)->get();
        $gamesB = Game::query()->where('group_id', $groupB->id)->get();

        $context->finishGameByEntryViaApi(
            $context->findGameBetweenEntries($gamesA, $entries[0]->id, $entries[1]->id),
            (int) $entries[0]->id,
        )->assertOk();
        $context->finishGameByEntryViaApi(
            $context->findGameBetweenEntries($gamesB, $entries[2]->id, $entries[3]->id),
            (int) $entries[2]->id,
        )->assertOk();

        $response = $context->createBracket($competition)->assertCreated();
        $side = $response->json('data.games.0.side1');

        $this->assertStringContainsString(' / ', $side['display_name']);
        $this->assertNotNull($side['group_origin']);
        $this->assertContains($side['group_origin']['group_name'], ['Grupo A', 'Grupo B']);
        $this->assertContains($side['group_origin']['position'], [1, 2]);
    }

    public function test_team_bracket_entries_include_group_origin(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 4, 4);
        $groupA = $context->createGroupWithEntries($competition, [$entries[0], $entries[1]], 'Grupo A');
        $groupB = $context->createGroupWithEntries($competition, [$entries[2], $entries[3]], 'Grupo B');

        $context->generateTeamRoundRobin($groupA)->assertCreated();
        $context->generateTeamRoundRobin($groupB)->assertCreated();
        $this->finishGroupWinner($context, $groupA, $entries, $entries[0]);
        $this->finishGroupWinner($context, $groupB, $entries, $entries[2]);

        $competition->update(['qualified_per_group' => 1]);
        $competition->refresh();

        $response = $context->createBracket($competition)->assertCreated();
        $entry1 = $response->json('data.team_ties.0.entry1');

        $this->assertNotNull($entry1['group_origin']);
        $this->assertSame(
            $this->originPayloadForEntry(
                Bracket::query()->where('competition_id', $competition->id)->sole()->id,
                (int) $entry1['competition_entry_id'],
            ),
            $entry1['group_origin'],
        );
    }

    public function test_knockout_direct_sides_have_null_group_origin(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);

        $response = $context->createBracket($competition)->assertCreated();

        foreach ($response->json('data.games') as $game) {
            $this->assertArrayHasKey('group_origin', $game['side1']);
            $this->assertNull($game['side1']['group_origin']);
            $this->assertArrayHasKey('group_origin', $game['side2']);
            $this->assertNull($game['side2']['group_origin']);
        }
    }

    public function test_group_games_and_team_ties_omit_group_origin(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $context->createBracket($setup['competition'])->assertCreated();

        $groupGame = Game::query()->where('group_id', $setup['groupA']->id)->sole();
        $groupGameResponse = $this->getJson($context->apiUrl("games/{$groupGame->id}"))->assertOk();
        $this->assertArrayNotHasKey('group_origin', $groupGameResponse->json('data.side1'));
        $this->assertArrayNotHasKey('group_origin', $groupGameResponse->json('data.side2'));

        $listed = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/games"))->assertOk();
        $listedGroupGame = collect($listed->json('data'))->first(
            fn (array $game): bool => (int) $game['group_id'] === (int) $setup['groupA']->id,
        );
        $this->assertNotNull($listedGroupGame);
        $this->assertArrayNotHasKey('group_origin', $listedGroupGame['side1']);

        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();

        $teamTieResponse = $context->listGroupTeamTies($group)->assertOk();
        $this->assertArrayNotHasKey('group_origin', $teamTieResponse->json('data.0.entry1'));
        $this->assertArrayNotHasKey('group_origin', $teamTieResponse->json('data.0.entry2'));
    }

    public function test_later_round_and_bye_preserve_entry_origin(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        $group = $context->createGroupWithPlayers($competition, $players, 'Grupo A');
        $context->generateRoundRobin($group)->assertCreated();
        $this->finishGroupRoundRobinWithRankOrder($context, $group->id, $players);

        $createResponse = $context->createBracket($competition)->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();

        $games = $createResponse->json('data.games');
        $bye = collect($games)->first(fn (array $game): bool => $game['is_bye'] === true);
        $real = collect($games)->first(fn (array $game): bool => $game['is_bye'] === false);

        $this->assertNotNull($bye);
        $this->assertNotNull($real);
        $this->assertSame(
            $this->originPayloadForEntry($bracket->id, (int) $bye['side1']['competition_entry_id']),
            $bye['side1']['group_origin'],
        );
        $this->assertNull($bye['side2']);
        $this->assertNotNull($real['side1']['group_origin']);
        $this->assertNotNull($real['side2']['group_origin']);

        $realGame = Game::query()->findOrFail($real['id']);
        $context->finishGameByEntryViaApi($realGame, (int) $realGame->entry1_id)->assertOk();

        $nextRound = $context->generateBracketNextRound($bracket)->assertCreated();
        $final = collect($nextRound->json('data.games'))->first(
            fn (array $game): bool => $game['round'] === 'Final',
        );

        $this->assertNotNull($final);
        $this->assertSame(
            $this->originPayloadForEntry($bracket->id, (int) $final['side1']['competition_entry_id']),
            $final['side1']['group_origin'],
        );
        $this->assertSame(
            $this->originPayloadForEntry($bracket->id, (int) $final['side2']['competition_entry_id']),
            $final['side2']['group_origin'],
        );
    }

    public function test_winner_correction_exposes_origin_of_the_new_entry(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $competition->update(['qualified_per_group' => 3]);
        $competition->refresh();

        $group = $context->createGroupWithPlayers($competition, $players, 'Grupo A');
        $context->generateRoundRobin($group)->assertCreated();
        $this->finishGroupRoundRobinWithRankOrder($context, $group->id, $players);

        $context->createBracket($competition)->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $realGame = Game::query()
            ->where('bracket_id', $bracket->id)
            ->where('is_bye', false)
            ->firstOrFail();

        $originalWinnerId = (int) $realGame->entry1_id;
        $newWinnerId = (int) $realGame->entry2_id;

        $context->finishGameByEntryViaApi($realGame, $originalWinnerId)->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $pointsPerSet = (int) $competition->points_per_set;
        $context->correctResult($realGame->fresh(), 'ajuste de origin', [
            ['player1_score' => 0, 'player2_score' => $pointsPerSet],
        ])->assertOk();

        $final = collect($context->showBracket($competition)->assertOk()->json('data.games'))
            ->first(fn (array $game): bool => $game['round'] === 'Final');

        $this->assertNotNull($final);
        $finalEntryIds = [
            (int) $final['side1']['competition_entry_id'],
            (int) $final['side2']['competition_entry_id'],
        ];
        $this->assertContains($newWinnerId, $finalEntryIds);

        $newWinnerSide = (int) $final['side1']['competition_entry_id'] === $newWinnerId
            ? $final['side1']
            : $final['side2'];

        $this->assertSame(
            $this->originPayloadForEntry($bracket->id, $newWinnerId),
            $newWinnerSide['group_origin'],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $games
     */
    private function assertBracketGamesHaveOrigins(array $games): void
    {
        $this->assertNotEmpty($games);

        foreach ($games as $game) {
            foreach (['side1', 'side2'] as $sideKey) {
                $side = $game[$sideKey];

                if ($side === null) {
                    continue;
                }

                $this->assertNotNull($side['group_origin']);
                $this->assertSame(
                    $this->originPayloadForEntry(
                        (int) $game['bracket_id'],
                        (int) $side['competition_entry_id'],
                    ),
                    $side['group_origin'],
                );
            }
        }
    }

    /**
     * @return array{group_id: int, group_name: string, position: int}
     */
    private function originPayloadForEntry(int $bracketId, int $competitionEntryId): array
    {
        $origin = BracketEntryOrigin::query()
            ->where('bracket_id', $bracketId)
            ->where('competition_entry_id', $competitionEntryId)
            ->firstOrFail();

        return $origin->toSidePayload();
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
                        (int) $candidate->singlesPlayer1Id() === $left->id
                        && (int) $candidate->singlesPlayer2Id() === $right->id
                    ) || (
                        (int) $candidate->singlesPlayer1Id() === $right->id
                        && (int) $candidate->singlesPlayer2Id() === $left->id
                    ),
                );

                $this->assertNotNull($game);
                $context->finishGame($game, $winner)->assertOk();
            }
        }
    }

    /**
     * @param  list<CompetitionEntry>  $entries
     */
    private function finishGroupWinner(
        TournamentTestContext $context,
        Group $group,
        array $entries,
        CompetitionEntry $winner,
    ): void {
        $teamTies = TeamTie::query()->where('group_id', $group->id)->get();

        foreach ($teamTies as $teamTie) {
            $winnerId = (int) $winner->id === (int) $teamTie->entry1_id
                || (int) $winner->id === (int) $teamTie->entry2_id
                ? (int) $winner->id
                : (int) $teamTie->entry1_id;

            foreach ([1, 2, 3] as $slot) {
                $this->winRubber($context, $teamTie->fresh(), $entries, $slot, $winnerId);
            }
        }
    }

    /**
     * @param  list<CompetitionEntry>  $entries
     */
    private function winRubber(
        TournamentTestContext $context,
        TeamTie $teamTie,
        array $entries,
        int $slotOrder,
        int $winnerEntryId,
    ): void {
        $rubber = $teamTie->teamTieGames()->where('slot_order', $slotOrder)->firstOrFail();
        $entry1 = collect($entries)->firstWhere('id', $teamTie->entry1_id);
        $entry2 = collect($entries)->firstWhere('id', $teamTie->entry2_id);
        $requiredPerSide = $rubber->modality === TeamTieModality::Doubles ? 2 : 1;

        $context->setTeamTieGameLineup($rubber, [
            'entry1_player_ids' => $this->playerIds($entry1, $requiredPerSide),
            'entry2_player_ids' => $this->playerIds($entry2, $requiredPerSide),
        ])->assertOk();

        $context->finishGameByEntryViaApi($rubber->game->fresh(), $winnerEntryId)->assertOk();
    }

    /**
     * @return list<int>
     */
    private function playerIds(CompetitionEntry $entry, int $count): array
    {
        return CompetitionEntryMember::query()
            ->where('competition_entry_id', $entry->id)
            ->orderBy('member_order')
            ->limit($count)
            ->pluck('player_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
