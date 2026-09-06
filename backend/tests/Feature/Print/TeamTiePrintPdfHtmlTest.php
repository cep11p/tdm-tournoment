<?php

namespace Tests\Feature\Print;

use App\Actions\TeamTie\BuildPrintTeamTieAction;
use App\Enums\CompetitionFormat;
use App\Enums\GameStatus;
use App\Enums\TeamTieModality;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\Player;
use App\Models\TeamTie;
use App\Models\TeamTieFormat;
use App\Models\TeamTieGame;
use App\Support\Print\PrintPresentation;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class TeamTiePrintPdfHtmlTest extends TestCase
{
    public function test_pending_copa_5_renders_five_pending_rows_without_score(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();
        $html = $this->renderTeamTie($teamTie);

        $this->assertSame(5, substr_count($html, '>Pendiente<'));
        $this->assertStringContainsString('Individual 1', $html);
        $this->assertStringContainsString('Dobles', $html);
        $this->assertStringContainsString('Por definir', $html);
        $this->assertStringContainsString('Planilla de enfrentamiento', $html);
        $this->assertStringContainsString('Equipo 1 vs Equipo 2', $html);
        $this->assertStringContainsString('Equipos', $html);
        $this->assertStringContainsString('Grupo A · Ronda 1', $html);
        $this->assertStringContainsString('Formato: Copa 5', $html);
        $this->assertStringContainsString('Gana el primero en llegar a 3 victorias', $html);
        $this->assertStringNotContainsString('0 — 0', $html);
        $this->assertStringNotContainsString('Ganador:', $html);
        $this->assertStringNotContainsString('Singles', $html);
        $this->assertNoSetColumns($html);
    }

    public function test_complete_lineup_shows_names_without_por_definir(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();

        foreach ([1, 2, 3, 4, 5] as $slot) {
            $this->lineupRubber($teamTie, $entries, $slot);
        }

        $sheet = app(BuildPrintTeamTieAction::class)($teamTie->fresh());
        $html = $this->renderSheet($sheet);

        foreach ($sheet->rubbers as $rubber) {
            $this->assertStringContainsString(
                PrintPresentation::teamTieLineupLabel($rubber['side1']),
                $html,
            );
            $this->assertStringContainsString(
                PrintPresentation::teamTieLineupLabel($rubber['side2']),
                $html,
            );
        }

        $this->assertStringNotContainsString('Por definir', $html);
    }

    public function test_incomplete_lineup_shows_por_definir(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $this->lineupRubber($teamTie, $entries, 1);

        $html = $this->renderTeamTie($teamTie->fresh());

        $this->assertStringContainsString('Por definir', $html);
        $this->assertStringContainsString('Jugador1 Test', $html);
    }

    public function test_individual_and_doubles_labels_and_lineup(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $this->lineupRubber($teamTie, $entries, 1);
        $this->lineupRubber($teamTie, $entries, 3);

        $html = $this->renderTeamTie($teamTie->fresh());

        $this->assertStringContainsString('Individual 1', $html);
        $this->assertStringContainsString('Dobles', $html);
        $this->assertStringContainsString('Jugador1 Test / Jugador2 Test', $html);
        $this->assertStringNotContainsString('Singles', $html);
    }

    public function test_in_progress_rubber_shows_en_juego(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();
        $this->rubberAt($teamTie, 1)->game->update(['status' => GameStatus::InProgress]);

        $html = $this->renderTeamTie($teamTie->fresh());

        $this->assertStringContainsString('En juego', $html);
    }

    public function test_finished_three_one_shows_score_winner_and_check(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();

        $this->winRubber($context, $teamTie, $entries, 1, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 2, (int) $teamTie->entry2_id);
        $this->winRubber($context, $teamTie, $entries, 3, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 4, (int) $teamTie->entry1_id);

        $html = $this->renderTeamTie($teamTie->fresh());

        $this->assertStringContainsString('Equipo 1', $html);
        $this->assertStringContainsString('3 — 1', $html);
        $this->assertStringContainsString('Equipo 2', $html);
        $this->assertStringContainsString('Ganador: Equipo 1', $html);
        $this->assertStringContainsString('✓', $html);
        $this->assertStringNotContainsString('Finalizado', $html);
        $this->assertNoSetColumns($html);
    }

    public function test_not_needed_slots_keep_lineup_and_are_not_pending(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();

        foreach ([1, 2, 3, 4, 5] as $slot) {
            $this->lineupRubber($teamTie, $entries, $slot);
        }

        $this->winRubber($context, $teamTie->fresh(), $entries, 1, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie->fresh(), $entries, 2, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie->fresh(), $entries, 3, (int) $teamTie->entry1_id);

        $html = $this->renderTeamTie($teamTie->fresh());

        $this->assertSame(2, substr_count($html, 'No necesario'));
        $this->assertStringNotContainsString('>Pendiente<', $html);
        $this->assertStringContainsString('Jugador1 Test', $html);
        $this->assertStringContainsString('print-row--secondary', $html);
    }

    public function test_post_clinch_finished_rubber_is_not_official(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();

        $this->finishRubberSlotDirectly($teamTie, 1, (int) $teamTie->entry1_id);
        $this->finishRubberSlotDirectly($teamTie, 2, (int) $teamTie->entry1_id);
        $this->finishRubberSlotDirectly($teamTie, 3, (int) $teamTie->entry1_id);
        $this->finishRubberSlotDirectly($teamTie, 4, (int) $teamTie->entry2_id);
        $this->finishRubberSlotDirectly($teamTie, 5, (int) $teamTie->entry2_id);

        $html = $this->renderTeamTie($teamTie->fresh());

        $this->assertStringContainsString('3 — 0', $html);
        $this->assertStringContainsString('No oficial', $html);
        $this->assertStringNotContainsString('1 — 2', $html);
        $this->assertStringNotContainsString('2 — 3', $html);
        $this->assertStringContainsString('print-check--faint', $html);
    }

    public function test_format_snapshot_survives_live_format_rename(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();
        $teamTie->update(['format_name' => 'Copa Histórica']);
        TeamTieFormat::query()->whereKey($teamTie->team_tie_format_id)->update(['name' => 'Copa Nueva']);

        $html = $this->renderTeamTie($teamTie->fresh());

        $this->assertStringContainsString('Formato: Copa Histórica', $html);
        $this->assertStringNotContainsString('Copa Nueva', $html);
    }

    public function test_bye_renders_compact_block_without_table_or_score(): void
    {
        $html = $this->renderTeamTie($this->createByeTeamTie());

        $this->assertStringContainsString('Pase directo', $html);
        $this->assertStringNotContainsString('<table class="print-table print-team-tie-table">', $html);
        $this->assertStringNotContainsString('0 — 0', $html);
        $this->assertStringNotContainsString('Pendiente', $html);
        $this->assertStringNotContainsString('Los partidos internos aún no fueron generados.', $html);
    }

    public function test_empty_rubbers_show_generation_message(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();
        $this->deleteRubbers($teamTie);

        $html = $this->renderTeamTie($teamTie->fresh());

        $this->assertStringContainsString('Los partidos internos aún no fueron generados.', $html);
        $this->assertStringNotContainsString('<table class="print-table print-team-tie-table">', $html);
        $this->assertStringContainsString('Copa 5', $html);
    }

    public function test_unicode_spanish_names_are_preserved(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $this->renameEntryPlayer($entries[0], 'José', 'Muñoz');
        $this->renameEntryPlayer($entries[1], 'Peña', 'Ñandú');
        $this->lineupRubber($teamTie->fresh(), $entries, 1);

        $html = $this->renderTeamTie($teamTie->fresh());

        $this->assertStringContainsString('José Muñoz', $html);
        $this->assertStringContainsString('Peña Ñandú', $html);
        $this->assertStringContainsString('á é í ó ú ñ', $html);
        $this->assertStringContainsString('DejaVu Sans', $html);
    }

    private function assertNoSetColumns(string $html): void
    {
        $this->assertStringNotContainsString('Set 1', $html);
        $this->assertStringNotContainsString('best_of', $html);
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

    private function renderTeamTie(TeamTie $teamTie): string
    {
        return $this->renderSheet(app(BuildPrintTeamTieAction::class)($teamTie));
    }

    private function renderSheet(object $sheet): string
    {
        return view('pdf.team-ties.show', [
            'sheet' => $sheet,
            'title' => PrintPresentation::teamTieMatchupLabel($sheet),
        ])->render();
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

    private function renameEntryPlayer(CompetitionEntry $entry, string $firstName, string $lastName): void
    {
        $playerId = CompetitionEntryMember::query()
            ->where('competition_entry_id', $entry->id)
            ->orderBy('member_order')
            ->value('player_id');

        Player::query()->whereKey($playerId)->update([
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
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
