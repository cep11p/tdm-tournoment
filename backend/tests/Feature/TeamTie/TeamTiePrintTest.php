<?php

namespace Tests\Feature\TeamTie;

use App\Enums\CompetitionFormat;
use App\Enums\GameStatus;
use App\Enums\TeamTieModality;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\TeamTie;
use App\Models\TeamTieGame;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class TeamTiePrintTest extends TestCase
{
    public function test_group_team_tie_returns_print_payload(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();

        $response = $this->getJson($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print"));

        $response
            ->assertOk()
            ->assertJsonPath('data.tournament.name', 'Torneo Test')
            ->assertJsonPath('data.competition.type', 'team')
            ->assertJsonPath('data.team_tie.id', $teamTie->id)
            ->assertJsonPath('data.team_tie.context_label', 'Grupo A · Ronda 1')
            ->assertJsonPath('data.team_tie.is_bye', false)
            ->assertJsonPath('data.team_tie.victories_required', 3)
            ->assertJsonPath('data.format.name', 'Copa 5')
            ->assertJsonPath('data.format.slots_count', 5)
            ->assertJsonPath('data.side1.display_name', 'Equipo 1')
            ->assertJsonPath('data.side2.display_name', 'Equipo 2')
            ->assertJsonPath('data.score.side1', 0)
            ->assertJsonPath('data.score.side2', 0)
            ->assertJsonPath('data.winner', null)
            ->assertJsonCount(5, 'data.rubbers');
    }

    public function test_print_endpoint_remains_public(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();

        $this->getJson($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print"))
            ->assertOk();
    }

    public function test_bracket_semifinal_uses_round_label(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $context->registerTeams($competition, 4, 4);
        $context->createBracket($competition)->assertCreated();

        $semifinal = TeamTie::query()
            ->where('competition_id', $competition->id)
            ->where('is_bye', false)
            ->orderBy('bracket_match')
            ->firstOrFail();

        $this->getJson($context->apiUrl("team-ties/{$semifinal->id}/print"))
            ->assertOk()
            ->assertJsonPath('data.team_tie.context_label', 'Semifinal')
            ->assertJsonMissingPath('data.team_tie.bracket_round');
    }

    public function test_bracket_final_uses_round_label(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $context->registerTeams($competition, 2, 4);
        $context->createBracket($competition)->assertCreated();

        $final = TeamTie::query()
            ->where('competition_id', $competition->id)
            ->where('is_bye', false)
            ->sole();

        $this->getJson($context->apiUrl("team-ties/{$final->id}/print"))
            ->assertOk()
            ->assertJsonPath('data.team_tie.context_label', 'Final');
    }

    public function test_complete_lineup_is_exposed_on_rubber(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $this->lineupRubber($teamTie, $entries, 1);

        $rubber = $this->getJson($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print"))
            ->assertOk()
            ->json('data.rubbers.0');

        $this->assertTrue($rubber['lineup_complete']);
        $this->assertCount(1, $rubber['side1']['players']);
        $this->assertCount(1, $rubber['side2']['players']);
        $this->assertNotSame('', $rubber['side1']['players'][0]['name']);
    }

    public function test_partial_lineup_prints_remaining_slots_as_empty(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $this->lineupRubber($teamTie, $entries, 1);
        $this->lineupRubber($teamTie, $entries, 3);

        $rubbers = $this->getJson($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print"))
            ->assertOk()
            ->json('data.rubbers');

        $this->assertTrue($rubbers[0]['lineup_complete']);
        $this->assertFalse($rubbers[1]['lineup_complete']);
        $this->assertTrue($rubbers[2]['lineup_complete']);
        $this->assertSame([], $rubbers[1]['side1']['players']);
        $this->assertSame('doubles', $rubbers[2]['type']);
        $this->assertCount(2, $rubbers[2]['side1']['players']);
        $this->assertCount(2, $rubbers[2]['side2']['players']);
    }

    public function test_not_needed_rubbers_remain_visible(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();

        $this->winRubber($context, $teamTie, $entries, 1, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 2, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 3, (int) $teamTie->entry1_id);

        $payload = $this->getJson($context->apiUrl("team-ties/{$teamTie->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame('not_needed', $payload['rubbers'][3]['status']);
        $this->assertSame('not_needed', $payload['rubbers'][4]['status']);
        $this->assertFalse($payload['rubbers'][3]['official']);
        $this->assertSame(3, $payload['score']['side1']);
        $this->assertSame(0, $payload['score']['side2']);
        $this->assertSame('finished', $payload['team_tie']['status']);
        $this->assertSame((int) $teamTie->entry1_id, $payload['winner']['competition_entry_id']);
        $this->assertSame('Equipo 1', $payload['winner']['display_name']);
    }

    public function test_post_clinch_finished_rubber_is_not_official(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();

        $this->finishRubberSlotDirectly($teamTie, 1, (int) $teamTie->entry1_id);
        $this->finishRubberSlotDirectly($teamTie, 2, (int) $teamTie->entry1_id);
        $this->finishRubberSlotDirectly($teamTie, 3, (int) $teamTie->entry1_id);
        $this->finishRubberSlotDirectly($teamTie, 4, (int) $teamTie->entry2_id);
        $this->finishRubberSlotDirectly($teamTie, 5, (int) $teamTie->entry2_id);

        $payload = $this->getJson($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $payload['score']['side1']);
        $this->assertSame(0, $payload['score']['side2']);
        $this->assertTrue($payload['rubbers'][2]['official']);
        $this->assertFalse($payload['rubbers'][3]['official']);
        $this->assertSame('finished', $payload['rubbers'][3]['status']);
        $this->assertSame(2, $payload['rubbers'][3]['winner_side']);
    }

    public function test_bye_returns_compact_payload(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $context->registerTeams($competition, 3, 4);
        $context->createBracket($competition)->assertCreated();

        $bye = TeamTie::query()
            ->where('competition_id', $competition->id)
            ->where('is_bye', true)
            ->sole();

        $payload = $this->getJson($context->apiUrl("team-ties/{$bye->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertTrue($payload['team_tie']['is_bye']);
        $this->assertSame([], $payload['rubbers']);
        $this->assertNull($payload['side2']);
        $this->assertNotNull($payload['side1']);
        $this->assertSame($payload['side1']['competition_entry_id'], $payload['winner']['competition_entry_id']);
        $this->assertSame(0, $payload['format']['slots_count']);
    }

    public function test_team_tie_without_rubbers_returns_empty_list(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();
        $this->deleteRubbers($teamTie);

        $payload = $this->getJson($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertFalse($payload['team_tie']['is_bye']);
        $this->assertSame([], $payload['rubbers']);
        $this->assertSame(0, $payload['format']['slots_count']);
        $this->assertSame('Copa 5', $payload['format']['name']);
    }

    public function test_dto_does_not_expose_game_internals(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $this->lineupRubber($teamTie, $entries, 1);

        $payload = $this->getJson($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print"))
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('bracket_round', $payload['team_tie']);
        $this->assertArrayNotHasKey('rubbers_counting', $payload);
        $this->assertArrayNotHasKey('player1', $payload);
        $this->assertArrayNotHasKey('player2', $payload);

        $rubber = $payload['rubbers'][0];
        $this->assertArrayNotHasKey('game_id', $rubber);
        $this->assertArrayNotHasKey('sets', $rubber);
        $this->assertArrayNotHasKey('sets_won', $rubber);
        $this->assertArrayNotHasKey('best_of', $rubber);
        $this->assertArrayNotHasKey('player1', $rubber);
        $this->assertArrayNotHasKey('player2', $rubber);
        $this->assertArrayHasKey('label', $rubber);
        $this->assertArrayHasKey('official', $rubber);
        $this->assertArrayHasKey('winner_side', $rubber);
    }

    public function test_missing_team_tie_returns_not_found(): void
    {
        $this->getJson($this->tournamentContext()->apiUrl('team-ties/999999/print'))
            ->assertNotFound();
    }

    /**
     * @return array{0: TeamTie, 1: list<CompetitionEntry>}
     */
    private function createScheduledTeamTie(): array
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();

        $teamTie = TeamTie::query()->where('group_id', $group->id)->firstOrFail();

        return [$teamTie, $entries];
    }

    /**
     * @param  list<CompetitionEntry>  $entries
     */
    private function lineupRubber(TeamTie $teamTie, array $entries, int $slotOrder): void
    {
        $rubber = $this->rubberAt($teamTie, $slotOrder);
        $required = $rubber->modality === TeamTieModality::Doubles ? 2 : 1;

        $this->tournamentContext()
            ->setTeamTieGameLineup($rubber, [
                'entry1_player_ids' => $this->playerIds($entries[0], $required),
                'entry2_player_ids' => $this->playerIds($entries[1], $required),
            ])
            ->assertOk();
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
        $this->lineupRubber($teamTie->fresh(), $entries, $slotOrder);
        $rubber = $this->rubberAt($teamTie->fresh(), $slotOrder);
        $context->finishGameByEntryViaApi($rubber->game->fresh(), $winnerEntryId)->assertOk();
    }

    private function finishRubberSlotDirectly(TeamTie $teamTie, int $slotOrder, int $winnerEntryId): void
    {
        $this->rubberAt($teamTie, $slotOrder)->game->update([
            'status' => GameStatus::Finished,
            'winner_entry_id' => $winnerEntryId,
            'finished_at' => now(),
        ]);
    }

    private function rubberAt(TeamTie $teamTie, int $slotOrder): TeamTieGame
    {
        return $teamTie->teamTieGames()->where('slot_order', $slotOrder)->firstOrFail();
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

    private function deleteRubbers(TeamTie $teamTie): void
    {
        $teamTie->load('teamTieGames');

        foreach ($teamTie->teamTieGames as $rubber) {
            $gameId = (int) $rubber->game_id;
            $rubber->members()->delete();
            $rubber->delete();
            Game::query()->whereKey($gameId)->delete();
        }
    }
}
