<?php

namespace Tests\Unit\TeamTie;

use App\Enums\CompetitionFormat;
use App\Enums\GameStatus;
use App\Enums\TeamTieModality;
use App\Enums\TeamTieStatus;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\TeamTie;
use App\Models\TeamTieFormat;
use App\Models\TeamTieFormatSlot;
use App\Models\TeamTieGame;
use App\Support\Competition\CompetitionEntryDisplayName;
use App\Support\TeamTie\TeamTiePrintSlotLabel;
use App\Support\TeamTie\TeamTiePrintStructureBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeamTiePrintStructureBuilderTest extends TestCase
{
    public function test_copa_5_labels_number_singles_and_keep_single_doubles_unnumbered(): void
    {
        $this->assertSame(
            ['Individual 1', 'Individual 2', 'Dobles', 'Individual 3', 'Individual 4'],
            TeamTiePrintSlotLabel::forModalities([
                TeamTieModality::Singles,
                TeamTieModality::Singles,
                TeamTieModality::Doubles,
                TeamTieModality::Singles,
                TeamTieModality::Singles,
            ]),
        );
    }

    public function test_single_singles_label_is_unnumbered(): void
    {
        $this->assertSame(
            ['Individual'],
            TeamTiePrintSlotLabel::forModalities([TeamTieModality::Singles]),
        );
    }

    public function test_multiple_doubles_are_numbered(): void
    {
        $this->assertSame(
            ['Dobles 1', 'Dobles 2'],
            TeamTiePrintSlotLabel::forModalities([
                TeamTieModality::Doubles,
                TeamTieModality::Doubles,
            ]),
        );
    }

    public function test_mixed_three_slot_labels(): void
    {
        $this->assertSame(
            ['Individual 1', 'Dobles', 'Individual 2'],
            TeamTiePrintSlotLabel::forModalities(['singles', 'doubles', 'singles']),
        );
    }

    #[DataProvider('doesNotUseModalityEnumLabelProvider')]
    public function test_labels_never_use_singles_word(array $modalities): void
    {
        $labels = TeamTiePrintSlotLabel::forModalities($modalities);

        foreach ($labels as $label) {
            $this->assertStringNotContainsString('Singles', $label);
        }
    }

    /**
     * @return array<string, array{list<TeamTieModality>}>
     */
    public static function doesNotUseModalityEnumLabelProvider(): array
    {
        return [
            'copa 5' => [[
                TeamTieModality::Singles,
                TeamTieModality::Singles,
                TeamTieModality::Doubles,
                TeamTieModality::Singles,
                TeamTieModality::Singles,
            ]],
            's d s' => [[
                TeamTieModality::Singles,
                TeamTieModality::Doubles,
                TeamTieModality::Singles,
            ]],
        ];
    }

    public function test_rubbers_follow_slot_order_not_game_id(): void
    {
        $teamTie = $this->createScheduledTeamTie();
        $first = $this->rubberAt($teamTie, 1);
        $third = $this->rubberAt($teamTie, 3);

        $first->game->update(['id' => $first->game->id]);
        Game::query()->whereKey($third->game_id)->update(['id' => $third->game_id]);

        $payload = $this->printPayload($teamTie);

        $this->assertSame([1, 2, 3, 4, 5], array_column($payload['rubbers'], 'slot_order'));
        $this->assertSame(
            ['Individual 1', 'Individual 2', 'Dobles', 'Individual 3', 'Individual 4'],
            array_column($payload['rubbers'], 'label'),
        );
        $this->assertSame('Copa 5', $payload['format']['name']);
        $this->assertSame(5, $payload['format']['slots_count']);
    }

    public function test_singles_lineup_uses_one_player_per_side(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTieWithEntries();
        $this->lineupSlot($teamTie, $entries, 1);

        $payload = $this->printPayload($teamTie);
        $rubber = $payload['rubbers'][0];

        $this->assertSame('singles', $rubber['type']);
        $this->assertTrue($rubber['lineup_complete']);
        $this->assertCount(1, $rubber['side1']['players']);
        $this->assertCount(1, $rubber['side2']['players']);
        $this->assertNotSame('', $rubber['side1']['players'][0]['name']);
        $this->assertStringNotContainsString(' / ', $rubber['side1']['players'][0]['name']);
    }

    public function test_doubles_lineup_follows_player_order_not_player_id(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTieWithEntries();
        $doubles = $this->rubberAt($teamTie, 3);

        $entry1Ids = array_values(array_reverse($this->playerIds($entries[0], 2)));
        $entry2Ids = array_values(array_reverse($this->playerIds($entries[1], 2)));

        $this->tournamentContext()
            ->setTeamTieGameLineup($doubles, [
                'entry1_player_ids' => $entry1Ids,
                'entry2_player_ids' => $entry2Ids,
            ])
            ->assertOk();

        $payload = $this->printPayload($teamTie);
        $rubber = $payload['rubbers'][2];

        $this->assertSame('doubles', $rubber['type']);
        $this->assertSame('Dobles', $rubber['label']);
        $this->assertSame($entry1Ids, array_column($rubber['side1']['players'], 'id'));
        $this->assertSame($entry2Ids, array_column($rubber['side2']['players'], 'id'));
        $this->assertNotSame(
            collect($entry1Ids)->sort()->values()->all(),
            array_column($rubber['side1']['players'], 'id'),
        );
    }

    public function test_mixed_three_slot_format_uses_materialized_games(): void
    {
        $format = $this->createCustomFormat(
            'Copa 3',
            2,
            [TeamTieModality::Singles, TeamTieModality::Doubles, TeamTieModality::Singles],
        );
        $teamTie = $this->createScheduledTeamTieWithCustomFormat($format);
        $payload = $this->printPayload($teamTie);

        $this->assertSame(3, $payload['format']['slots_count']);
        $this->assertSame('Copa 3', $payload['format']['name']);
        $this->assertSame(2, $payload['team_tie']['victories_required']);
        $this->assertSame([1, 2, 3], array_column($payload['rubbers'], 'slot_order'));
        $this->assertSame(['singles', 'doubles', 'singles'], array_column($payload['rubbers'], 'type'));
        $this->assertSame(
            ['Individual 1', 'Dobles', 'Individual 2'],
            array_column($payload['rubbers'], 'label'),
        );
    }

    public function test_seven_slot_format_is_supported(): void
    {
        $format = $this->createCustomFormat('Copa 7', 4, [
            TeamTieModality::Singles,
            TeamTieModality::Singles,
            TeamTieModality::Doubles,
            TeamTieModality::Singles,
            TeamTieModality::Singles,
            TeamTieModality::Doubles,
            TeamTieModality::Singles,
        ]);
        $teamTie = $this->createScheduledTeamTieWithCustomFormat($format);
        $payload = $this->printPayload($teamTie);

        $this->assertSame(7, $payload['format']['slots_count']);
        $this->assertCount(7, $payload['rubbers']);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_column($payload['rubbers'], 'slot_order'));
        $this->assertSame(
            ['Individual 1', 'Individual 2', 'Dobles 1', 'Individual 3', 'Individual 4', 'Dobles 2', 'Individual 5'],
            array_column($payload['rubbers'], 'label'),
        );
    }

    public function test_pending_rubber_without_lineup_has_empty_players(): void
    {
        $payload = $this->printPayload($this->createScheduledTeamTie());
        $rubber = $payload['rubbers'][0];

        $this->assertSame('pending', $rubber['status']);
        $this->assertFalse($rubber['official']);
        $this->assertFalse($rubber['lineup_complete']);
        $this->assertSame([], $rubber['side1']['players']);
        $this->assertSame([], $rubber['side2']['players']);
        $this->assertNull($rubber['winner_side']);
    }

    public function test_in_progress_rubber_keeps_status_without_winner(): void
    {
        $teamTie = $this->createScheduledTeamTie();
        $this->rubberAt($teamTie, 1)->game->update(['status' => GameStatus::InProgress]);

        $payload = $this->printPayload($teamTie);

        $this->assertSame('in_progress', $payload['rubbers'][0]['status']);
        $this->assertNull($payload['rubbers'][0]['winner_side']);
        $this->assertFalse($payload['rubbers'][0]['official']);
    }

    public function test_finished_official_rubber_exposes_winner_side(): void
    {
        $teamTie = $this->createScheduledTeamTie();
        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);

        $payload = $this->printPayload($teamTie);
        $rubber = $payload['rubbers'][0];

        $this->assertSame('finished', $rubber['status']);
        $this->assertTrue($rubber['official']);
        $this->assertSame(1, $rubber['winner_side']);
        $this->assertSame(1, $payload['score']['side1']);
        $this->assertSame(0, $payload['score']['side2']);
        $this->assertNull($payload['winner']);
    }

    public function test_not_needed_rubber_stays_visible(): void
    {
        $teamTie = $this->createScheduledTeamTie();
        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 2, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 3, (int) $teamTie->entry1_id);
        $this->rubberAt($teamTie, 4)->game->update(['status' => GameStatus::NotNeeded]);
        $this->rubberAt($teamTie, 5)->game->update(['status' => GameStatus::NotNeeded]);

        $payload = $this->printPayload($teamTie);

        $this->assertCount(5, $payload['rubbers']);
        $this->assertSame('not_needed', $payload['rubbers'][3]['status']);
        $this->assertSame('not_needed', $payload['rubbers'][4]['status']);
        $this->assertFalse($payload['rubbers'][3]['official']);
        $this->assertNull($payload['rubbers'][3]['winner_side']);
        $this->assertSame(3, $payload['score']['side1']);
        $this->assertSame(0, $payload['score']['side2']);
    }

    public function test_post_clinch_finished_rubber_is_not_official_and_is_ignored_in_score(): void
    {
        $teamTie = $this->createScheduledTeamTie();
        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 2, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 3, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 4, (int) $teamTie->entry2_id);
        $this->finishRubberSlot($teamTie, 5, (int) $teamTie->entry2_id);

        $payload = $this->printPayload($teamTie);

        $this->assertSame(3, $payload['score']['side1']);
        $this->assertSame(0, $payload['score']['side2']);
        $this->assertTrue($payload['rubbers'][0]['official']);
        $this->assertTrue($payload['rubbers'][2]['official']);
        $this->assertFalse($payload['rubbers'][3]['official']);
        $this->assertFalse($payload['rubbers'][4]['official']);
        $this->assertSame('finished', $payload['rubbers'][3]['status']);
        $this->assertSame(2, $payload['rubbers'][3]['winner_side']);
    }

    public function test_bye_returns_compact_payload_without_rubbers(): void
    {
        $teamTie = $this->createByeTeamTie();
        $payload = $this->printPayload($teamTie);

        $this->assertTrue($payload['team_tie']['is_bye']);
        $this->assertSame([], $payload['rubbers']);
        $this->assertSame(0, $payload['format']['slots_count']);
        $this->assertNotNull($payload['side1']);
        $this->assertNull($payload['side2']);
        $this->assertSame($payload['side1'], $payload['winner']);
        $this->assertNotSame('', $payload['team_tie']['context_label']);
        $this->assertArrayNotHasKey('bracket_round', $payload['team_tie']);
    }

    public function test_group_context_label_includes_round(): void
    {
        $teamTie = $this->createScheduledTeamTie();
        $payload = $this->printPayload($teamTie);

        $this->assertSame('Grupo A · Ronda 1', $payload['team_tie']['context_label']);
    }

    public function test_missing_lineup_does_not_block_print(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTieWithEntries();
        $this->lineupSlot($teamTie, $entries, 1);

        $payload = $this->printPayload($teamTie);

        $this->assertTrue($payload['rubbers'][0]['lineup_complete']);
        $this->assertFalse($payload['rubbers'][1]['lineup_complete']);
        $this->assertSame([], $payload['rubbers'][1]['side1']['players']);
    }

    public function test_print_payload_is_deterministic(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTieWithEntries();
        $this->lineupSlot($teamTie, $entries, 1);
        $this->finishRubberSlot($teamTie, 2, (int) $teamTie->entry2_id);

        $first = $this->printPayload($teamTie);
        $second = $this->printPayload($teamTie);

        $this->assertSame($first, $second);
    }

    public function test_does_not_use_game_entries_as_athletes(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTieWithEntries();
        $this->lineupSlot($teamTie, $entries, 1);

        $payload = $this->printPayload($teamTie);
        $rubber = $this->rubberAt($teamTie->fresh('teamTieGames.game'), 1);
        $game = $rubber->game;
        $teamName = CompetitionEntryDisplayName::for($entries[0]->fresh('members.player'));

        $this->assertSame((int) $teamTie->entry1_id, (int) $game->entry1_id);
        $this->assertSame((int) $teamTie->entry2_id, (int) $game->entry2_id);
        $this->assertNotSame($teamName, $payload['rubbers'][0]['side1']['players'][0]['name']);
        $this->assertSame($teamName, $payload['side1']['display_name']);
        $this->assertArrayNotHasKey('game_id', $payload['rubbers'][0]);
        $this->assertArrayNotHasKey('sets', $payload['rubbers'][0]);
        $this->assertArrayNotHasKey('player1', $payload['rubbers'][0]);
    }

    public function test_format_name_uses_team_tie_snapshot_not_live_format(): void
    {
        $teamTie = $this->createScheduledTeamTie();
        $teamTie->update(['format_name' => 'Copa Histórica']);
        TeamTieFormat::query()->whereKey($teamTie->team_tie_format_id)->update(['name' => 'Copa Nueva']);

        $payload = $this->printPayload($teamTie->fresh());

        $this->assertSame('Copa Histórica', $payload['format']['name']);
        $this->assertNotSame('Copa Nueva', $payload['format']['name']);
    }

    public function test_finished_team_tie_exposes_global_winner(): void
    {
        $teamTie = $this->createScheduledTeamTie();
        $this->finishRubberSlot($teamTie, 1, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 2, (int) $teamTie->entry1_id);
        $this->finishRubberSlot($teamTie, 3, (int) $teamTie->entry1_id);
        $teamTie->update([
            'status' => TeamTieStatus::Finished,
            'winner_entry_id' => $teamTie->entry1_id,
            'finished_at' => now(),
        ]);

        $payload = $this->printPayload($teamTie->fresh());

        $this->assertSame('finished', $payload['team_tie']['status']);
        $this->assertSame((int) $teamTie->entry1_id, $payload['winner']['competition_entry_id']);
        $this->assertSame($payload['side1']['display_name'], $payload['winner']['display_name']);
    }

    public function test_empty_rubbers_keep_snapshot_without_live_format_slots(): void
    {
        $teamTie = $this->createScheduledTeamTie();
        $this->deleteRubbers($teamTie);
        TeamTieFormatSlot::query()
            ->where('team_tie_format_id', $teamTie->team_tie_format_id)
            ->delete();

        $payload = $this->printPayload($teamTie->fresh());

        $this->assertSame([], $payload['rubbers']);
        $this->assertSame(0, $payload['format']['slots_count']);
        $this->assertSame('Copa 5', $payload['format']['name']);
        $this->assertFalse($payload['team_tie']['is_bye']);
    }

    /**
     * @return array{0: TeamTie, 1: list<CompetitionEntry>}
     */
    private function createScheduledTeamTieWithEntries(): array
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();

        $teamTie = TeamTie::query()->where('group_id', $group->id)->firstOrFail();

        return [$teamTie, $entries];
    }

    private function createScheduledTeamTie(): TeamTie
    {
        return $this->createScheduledTeamTieWithEntries()[0];
    }

    private function createScheduledTeamTieWithCustomFormat(TeamTieFormat $format): TeamTie
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $competition->update(['team_tie_format_id' => $format->id]);
        $entries = $context->registerTeams($competition, 2, 4);
        $group = $context->createGroupWithEntries($competition, $entries);
        $context->generateTeamRoundRobin($group)->assertCreated();

        return TeamTie::query()->where('group_id', $group->id)->firstOrFail();
    }

    private function createByeTeamTie(): TeamTie
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4, format: CompetitionFormat::KnockoutDirect);
        $context->registerTeams($competition, 3, 4);
        $context->createBracket($competition)->assertCreated();

        return TeamTie::query()
            ->where('competition_id', $competition->id)
            ->where('is_bye', true)
            ->sole();
    }

    /**
     * @param  list<TeamTieModality>  $modalities
     */
    private function createCustomFormat(string $name, int $victoriesRequired, array $modalities): TeamTieFormat
    {
        $format = TeamTieFormat::query()->create([
            'name' => $name,
            'description' => 'Formato de prueba print',
            'victories_required' => $victoriesRequired,
            'active' => true,
        ]);

        foreach ($modalities as $index => $modality) {
            TeamTieFormatSlot::query()->create([
                'team_tie_format_id' => $format->id,
                'slot_order' => $index + 1,
                'modality' => $modality,
            ]);
        }

        return $format->fresh('slots');
    }

    /**
     * @return array<string, mixed>
     */
    private function printPayload(TeamTie $teamTie): array
    {
        return (new TeamTiePrintStructureBuilder)->build($teamTie)->toArray();
    }

    private function rubberAt(TeamTie $teamTie, int $slotOrder): TeamTieGame
    {
        return $teamTie->teamTieGames()->where('slot_order', $slotOrder)->firstOrFail();
    }

    /**
     * @param  list<CompetitionEntry>  $entries
     */
    private function lineupSlot(TeamTie $teamTie, array $entries, int $slotOrder): void
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

    private function finishRubberSlot(TeamTie $teamTie, int $slotOrder, int $winnerEntryId): void
    {
        $this->rubberAt($teamTie, $slotOrder)->game->update([
            'status' => GameStatus::Finished,
            'winner_entry_id' => $winnerEntryId,
            'finished_at' => now(),
        ]);
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
