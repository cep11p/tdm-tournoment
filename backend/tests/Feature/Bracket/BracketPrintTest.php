<?php

namespace Tests\Feature\Bracket;

use App\Enums\CompetitionFormat;
use App\Enums\GameStatus;
use App\Enums\TeamTieModality;
use App\Enums\ThirdPlaceMode;
use App\Models\Bracket;
use App\Models\BracketEntryOrigin;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\Group;
use App\Models\Player;
use App\Models\TeamTie;
use App\Support\Competition\CompetitionEntryDisplayName;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class BracketPrintTest extends TestCase
{
    public function test_singles_direct_knockout_returns_print_payload(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::None]);
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket/print"));

        $response
            ->assertOk()
            ->assertJsonPath('data.tournament.name', 'Torneo Test')
            ->assertJsonPath('data.competition.id', $competition->id)
            ->assertJsonPath('data.competition.type', 'singles')
            ->assertJsonPath('data.bracket.bracket_size', 4)
            ->assertJsonPath('data.bracket.rounds_count', 2)
            ->assertJsonCount(2, 'data.rounds')
            ->assertJsonPath('data.rounds.0.label', 'Semifinal')
            ->assertJsonCount(2, 'data.rounds.0.matches')
            ->assertJsonPath('data.rounds.1.label', 'Final')
            ->assertJsonCount(1, 'data.rounds.1.matches')
            ->assertJsonPath('data.third_place', null)
            ->assertJsonPath('data.champion', null);

        $firstMatch = $response->json('data.rounds.0.matches.0');
        $this->assertTrue($firstMatch['exists_in_database']);
        $this->assertArrayHasKey('display_name', $firstMatch['side1']);
        $this->assertArrayHasKey('competition_entry_id', $firstMatch['side1']);
        $this->assertArrayHasKey('group_origin', $firstMatch['side1']);
        $this->assertNull($firstMatch['side1']['group_origin']);
        $this->assertArrayHasKey('group_origin', $firstMatch['side2']);
        $this->assertNull($firstMatch['side2']['group_origin']);
        $this->assertArrayNotHasKey('player1', $firstMatch);
        $this->assertArrayNotHasKey('player2', $firstMatch);
        $this->assertArrayNotHasKey('sets', $firstMatch);
        $this->assertArrayNotHasKey('best_of', $firstMatch);
        $this->assertArrayNotHasKey('referee', $firstMatch);
        $this->assertArrayNotHasKey('score', $firstMatch);
    }

    public function test_doubles_uses_pair_display_name(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPairs($competition, [
            [$players[0], $players[1]],
            [$players[2], $players[3]],
        ]);
        $context->createBracket($competition)->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket/print"));

        $response
            ->assertOk()
            ->assertJsonPath('data.competition.type', 'doubles');

        $side1 = $response->json('data.rounds.0.matches.0.side1');
        $this->assertStringContainsString(' / ', $side1['display_name']);
        $this->assertArrayNotHasKey('player1', $response->json('data.rounds.0.matches.0'));
        $this->assertArrayNotHasKey('id', $side1);
        $this->assertArrayNotHasKey('members', $side1);
        $this->assertArrayHasKey('group_origin', $side1);
        $this->assertNull($side1['group_origin']);
    }

    public function test_team_print_uses_team_display_name_without_rubbers(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $context->registerTeams($competition, 2, 4);
        $context->createBracket($competition)->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket/print"));

        $response
            ->assertOk()
            ->assertJsonPath('data.competition.type', 'team')
            ->assertJsonPath('data.bracket.bracket_size', 2)
            ->assertJsonPath('data.rounds.0.label', 'Final');

        $final = $response->json('data.rounds.0.matches.0');
        $this->assertStringStartsWith('Equipo ', $final['side1']['display_name']);
        $this->assertArrayHasKey('group_origin', $final['side1']);
        $this->assertNull($final['side1']['group_origin']);
        $this->assertArrayNotHasKey('score', $final);
        $this->assertArrayNotHasKey('rubbers_total', $final);
        $this->assertArrayNotHasKey('team_tie_games', $final);
        $this->assertArrayNotHasKey('sets', $final);
    }

    public function test_groups_knockout_prints_qualifiers_without_standings(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $setup['competition']->update(['third_place_mode' => ThirdPlaceMode::None]);
        $context->createBracket($setup['competition'])->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/bracket/print"));

        $response
            ->assertOk()
            ->assertJsonPath('data.bracket.bracket_size', 4)
            ->assertJsonPath('data.rounds.0.label', 'Semifinal');

        $encoded = json_encode($response->json('data'));
        $this->assertStringNotContainsString('standings', $encoded);

        foreach ($response->json('data.rounds.0.matches') as $match) {
            foreach (['side1', 'side2'] as $sideKey) {
                $side = $match[$sideKey];

                if ($side === null) {
                    continue;
                }

                $this->assertPrintGroupOrigin(
                    $bracket->id,
                    (int) $side['competition_entry_id'],
                    $side['group_origin'],
                );
            }
        }
    }

    public function test_only_first_round_infers_future_rounds(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Playoff]);
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket/print"));

        $response->assertOk();

        $semifinals = $response->json('data.rounds.0.matches');
        $final = $response->json('data.rounds.1.matches.0');

        $this->assertTrue($semifinals[0]['exists_in_database']);
        $this->assertFalse($final['exists_in_database']);
        $this->assertSame('not_created', $final['status']);
        $this->assertNull($final['side1']);
        $this->assertNull($final['side2']);
        $this->assertSame('Ganador P1', $final['side1_placeholder']);
        $this->assertSame('Ganador P2', $final['side2_placeholder']);
        $this->assertArrayNotHasKey('group_origin', $final);
        $this->assertFalse($response->json('data.third_place.exists_in_database'));
        $this->assertNull($response->json('data.third_place.side1'));
        $this->assertSame('Perdedor semifinal 1', $response->json('data.third_place.side1_placeholder'));
    }

    public function test_finished_match_exposes_winner_and_champion(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::None]);
        $players = $context->createPlayers(2);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $final = $context->bracketGamesForRound($bracket, 1)->firstOrFail();
        $context->finishGame($final, $players[0])->assertOk();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket/print"));

        $entryName = CompetitionEntryDisplayName::for($final->fresh()->entry1);

        $response
            ->assertOk()
            ->assertJsonPath('data.rounds.0.matches.0.status', GameStatus::Finished->value)
            ->assertJsonPath('data.champion.display_name', $entryName)
            ->assertJsonPath('data.rounds.0.matches.0.winner.display_name', $entryName);

        $match = $response->json('data.rounds.0.matches.0');
        $this->assertArrayNotHasKey('sets', $match);
        $this->assertArrayNotHasKey('sets_won', $match);
    }

    public function test_bye_is_represented_without_empty_opponent(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Playoff]);
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket/print"));

        $response
            ->assertOk()
            ->assertJsonPath('data.bracket.byes_count', 1);

        $bye = collect($response->json('data.rounds.0.matches'))
            ->first(fn (array $match): bool => $match['is_bye'] === true);

        $this->assertNotNull($bye);
        $this->assertNotNull($bye['side1']);
        $this->assertNull($bye['side2']);
        $this->assertSame($bye['side1']['display_name'], $bye['winner']['display_name']);

        $final = $response->json('data.rounds.1.matches.0');
        $this->assertSame($bye['side1']['display_name'], $final['side1']['display_name']);
        $this->assertNull($final['side1_placeholder']);
        $this->assertNull($response->json('data.third_place'));
    }

    public function test_playoff_third_place_is_created_after_advancing_to_final(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Playoff]);
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1)->sortBy('bracket_match')->values();
        $context->finishGame($semifinals[0], $semifinals[0]->singlesPlayer1())->assertOk();
        $context->finishGame($semifinals[1], $semifinals[1]->singlesPlayer1())->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket/print"));

        $response
            ->assertOk()
            ->assertJsonPath('data.third_place.mode', ThirdPlaceMode::Playoff->value)
            ->assertJsonPath('data.third_place.exists_in_database', true)
            ->assertJsonPath('data.rounds.1.matches.0.exists_in_database', true);

        $this->assertNotNull($response->json('data.third_place.side1.display_name'));
        $this->assertNotNull($response->json('data.third_place.side2.display_name'));
    }

    public function test_shared_third_place_has_no_match(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::Shared]);
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket/print"))
            ->assertOk()
            ->assertJsonPath('data.third_place.mode', ThirdPlaceMode::Shared->value)
            ->assertJsonPath('data.third_place.label', 'Tercer puesto compartido')
            ->assertJsonMissingPath('data.third_place.exists_in_database');
    }

    public function test_none_third_place_is_omitted(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::None]);
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket/print"))
            ->assertOk()
            ->assertJsonPath('data.third_place', null);
    }

    public function test_missing_bracket_returns_not_found(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket/print"))
            ->assertNotFound()
            ->assertJsonPath('message', 'La competencia no tiene un cuadro eliminatorio.');
    }

    public function test_correction_is_reflected_on_second_get(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $competition->update(['third_place_mode' => ThirdPlaceMode::None]);
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();
        $semifinal = $context->bracketGamesForRound($bracket, 1)
            ->sortBy('bracket_match')
            ->first();

        $context->finishGame($semifinal, $semifinal->singlesPlayer1())->assertOk();
        $semifinal = $semifinal->fresh(['winnerEntry', 'entry1', 'entry2']);

        $firstWinnerName = CompetitionEntryDisplayName::for($semifinal->winnerEntry);

        $first = $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket/print"))
            ->assertOk()
            ->json('data.rounds.1.matches.0.side1.display_name');

        $this->assertSame($firstWinnerName, $first);

        $newWinner = $semifinal->entry2;
        $semifinal->update([
            'winner_entry_id' => $newWinner->id,
        ]);

        $secondWinnerName = CompetitionEntryDisplayName::for($newWinner->fresh());
        $second = $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket/print"))
            ->assertOk()
            ->json('data.rounds.1.matches.0.side1.display_name');

        $this->assertSame($secondWinnerName, $second);
        $this->assertNotSame($first, $second);
    }

    public function test_print_does_not_expose_game_or_player_legacy_fields(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createKnockoutDirectCompetition();
        $players = $context->createPlayers(4);
        $context->registerPlayers($competition, $players);
        $context->createBracket($competition)->assertCreated();

        $payload = $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket/print"))
            ->assertOk()
            ->json('data');

        $encoded = json_encode($payload);
        $this->assertStringNotContainsString('"player1"', $encoded);
        $this->assertStringNotContainsString('"player2"', $encoded);
        $this->assertStringNotContainsString('"winner_id"', $encoded);
        $this->assertStringNotContainsString('"sets"', $encoded);
        $this->assertStringNotContainsString('"best_of"', $encoded);
        $this->assertStringNotContainsString('"referee"', $encoded);
        $this->assertStringNotContainsString('"sets_won"', $encoded);

        $this->assertGreaterThan(0, Game::query()->where('competition_id', $competition->id)->count());
    }

    public function test_bye_from_groups_preserves_origin_on_real_side(): void
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

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket/print"))->assertOk();
        $bye = collect($response->json('data.rounds.0.matches'))
            ->first(fn (array $match): bool => $match['is_bye'] === true);

        $this->assertNotNull($bye);
        $this->assertNotNull($bye['side1']);
        $this->assertNull($bye['side2']);
        $this->assertPrintGroupOrigin(
            $bracket->id,
            (int) $bye['side1']['competition_entry_id'],
            $bye['side1']['group_origin'],
        );
        $this->assertPrintGroupOrigin(
            $bracket->id,
            (int) $bye['winner']['competition_entry_id'],
            $bye['winner']['group_origin'],
        );
    }

    public function test_inferred_later_round_preserves_winner_group_origin(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $setup['competition']->update(['third_place_mode' => ThirdPlaceMode::None]);
        $context->createBracket($setup['competition'])->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinal = $context->bracketGamesForRound($bracket, 1)->sortBy('bracket_match')->first();
        $context->finishGame($semifinal, $semifinal->singlesPlayer1())->assertOk();

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/bracket/print"))->assertOk();
        $final = $response->json('data.rounds.1.matches.0');

        $this->assertFalse($final['exists_in_database']);
        $this->assertNotNull($final['side1']);
        $this->assertNull($final['side1_placeholder']);
        $this->assertNull($final['side2']);
        $this->assertSame('Ganador P2', $final['side2_placeholder']);
        $this->assertPrintGroupOrigin(
            $bracket->id,
            (int) $final['side1']['competition_entry_id'],
            $final['side1']['group_origin'],
        );
    }

    public function test_persisted_later_round_preserves_group_origin(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $setup['competition']->update(['third_place_mode' => ThirdPlaceMode::None]);
        $context->createBracket($setup['competition'])->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1)->sortBy('bracket_match')->values();
        $context->finishGame($semifinals[0], $semifinals[0]->singlesPlayer1())->assertOk();
        $context->finishGame($semifinals[1], $semifinals[1]->singlesPlayer1())->assertOk();
        $context->generateBracketNextRound($bracket)->assertCreated();

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/bracket/print"))->assertOk();
        $final = $response->json('data.rounds.1.matches.0');

        $this->assertTrue($final['exists_in_database']);
        $this->assertPrintGroupOrigin(
            $bracket->id,
            (int) $final['side1']['competition_entry_id'],
            $final['side1']['group_origin'],
        );
        $this->assertPrintGroupOrigin(
            $bracket->id,
            (int) $final['side2']['competition_entry_id'],
            $final['side2']['group_origin'],
        );
    }

    public function test_team_groups_knockout_print_includes_entry_origin(): void
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
        $context->createBracket($competition)->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $competition->id)->sole();

        $response = $this->getJson($context->apiUrl("competitions/{$competition->id}/bracket/print"))->assertOk();
        $final = $response->json('data.rounds.0.matches.0');

        $this->assertPrintGroupOrigin(
            $bracket->id,
            (int) $final['side1']['competition_entry_id'],
            $final['side1']['group_origin'],
        );
        $this->assertPrintGroupOrigin(
            $bracket->id,
            (int) $final['side2']['competition_entry_id'],
            $final['side2']['group_origin'],
        );
    }

    public function test_third_place_print_preserves_real_participant_origin(): void
    {
        $context = $this->tournamentContext();
        $setup = $context->createFourQualifierGroupPhase();
        $setup['competition']->update(['third_place_mode' => ThirdPlaceMode::Playoff]);
        $context->createBracket($setup['competition'])->assertCreated();
        $bracket = Bracket::query()->where('competition_id', $setup['competition']->id)->sole();
        $semifinals = $context->bracketGamesForRound($bracket, 1)->sortBy('bracket_match')->values();
        $context->finishGame($semifinals[0], $semifinals[0]->singlesPlayer1())->assertOk();
        $context->finishGame($semifinals[1], $semifinals[1]->singlesPlayer1())->assertOk();

        $response = $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/bracket/print"))->assertOk();
        $thirdPlace = $response->json('data.third_place');

        $this->assertSame(ThirdPlaceMode::Playoff->value, $thirdPlace['mode']);
        $this->assertFalse($thirdPlace['exists_in_database']);
        $this->assertPrintGroupOrigin(
            $bracket->id,
            (int) $thirdPlace['side1']['competition_entry_id'],
            $thirdPlace['side1']['group_origin'],
        );
        $this->assertPrintGroupOrigin(
            $bracket->id,
            (int) $thirdPlace['side2']['competition_entry_id'],
            $thirdPlace['side2']['group_origin'],
        );
    }

    /**
     * @param  array<string, mixed>|null  $origin
     */
    private function assertPrintGroupOrigin(int $bracketId, int $competitionEntryId, ?array $origin): void
    {
        $expected = BracketEntryOrigin::query()
            ->where('bracket_id', $bracketId)
            ->where('competition_entry_id', $competitionEntryId)
            ->firstOrFail()
            ->toSidePayload();

        $this->assertNotNull($origin);
        $this->assertSame(['group_id', 'group_name', 'position'], array_keys($origin));
        $this->assertArrayNotHasKey('group_position', $origin);
        $this->assertSame($expected, $origin);
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
