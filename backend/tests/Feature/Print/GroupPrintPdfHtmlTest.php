<?php

namespace Tests\Feature\Print;

use App\Actions\Group\BuildPrintGroupSheetAction;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Group;
use App\Models\Player;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class GroupPrintPdfHtmlTest extends TestCase
{
    public function test_g3_uses_official_layout_with_matrix_and_match_order(): void
    {
        $html = $this->renderGroupSheet(playerCount: 3, setsToWin: 2);

        $this->assertStringContainsString('data-sheet-kind="g3"', $html);
        $this->assertStringContainsString('pdf-sheet--official', $html);
        $this->assertStringContainsString('class="official-matrix"', $html);
        $this->assertStringContainsString('Observaciones', $html);
        $this->assertStringContainsString('Firma:', $html);
        $this->assertStringContainsString('Aclaración:', $html);
        $this->assertStringContainsString('Árbitro general / Responsable', $html);
        $this->assertStringNotContainsString('Participante A', $html);
        $this->assertSame(3, substr_count($html, 'official-matrix-row'));
        $this->assertSame(3, substr_count($html, 'matrix-cell--self">'));
        $this->assertSame(3, substr_count($html, '<table class="official-match">'));
        $this->assertSame([[1, 3], [1, 2], [2, 3]], $this->officialPairings($html));
        $this->assertSetLabels($html, 3, matchBlocks: 3);
        $this->assertStringNotContainsString('game_id', $html);
        $this->assertStringNotContainsString('Ganador', $html);
    }

    public function test_g3_best_of_three_renders_s1_to_s3(): void
    {
        $html = $this->renderGroupSheet(playerCount: 3, setsToWin: 2);

        $this->assertSetLabels($html, 3, matchBlocks: 3);
        $this->assertStringContainsString('Mejor de 3', $html);
    }

    public function test_g3_best_of_five_renders_s1_to_s5(): void
    {
        $html = $this->renderGroupSheet(playerCount: 3, setsToWin: 3);

        $this->assertSetLabels($html, 5, matchBlocks: 3);
        $this->assertStringContainsString('Mejor de 5', $html);
    }

    public function test_g3_best_of_seven_renders_s1_to_s7(): void
    {
        $html = $this->renderGroupSheet(playerCount: 3, setsToWin: 4);

        $this->assertSetLabels($html, 7, matchBlocks: 3);
        $this->assertStringContainsString('Mejor de 7', $html);
    }

    public function test_g4_uses_official_layout_with_exact_order(): void
    {
        $html = $this->renderGroupSheet(playerCount: 4, setsToWin: 2);

        $this->assertStringContainsString('data-sheet-kind="g4"', $html);
        $this->assertSame(4, substr_count($html, 'official-matrix-row'));
        $this->assertSame(4, substr_count($html, 'matrix-cell--self">'));
        $this->assertSame(6, substr_count($html, '<table class="official-match">'));
        $this->assertSame(
            [[1, 3], [2, 4], [1, 2], [3, 4], [1, 4], [2, 3]],
            $this->officialPairings($html),
        );
        $this->assertSetLabels($html, 3, matchBlocks: 6);
        $this->assertSame(3, substr_count($html, 'official-match-row--pair"'));
    }

    public function test_g5_uses_official_layout_with_orientation_preserved(): void
    {
        $html = $this->renderGroupSheet(playerCount: 5, setsToWin: 2);

        $this->assertStringContainsString('data-sheet-kind="g5"', $html);
        $this->assertSame(5, substr_count($html, 'official-matrix-row'));
        $this->assertSame(5, substr_count($html, 'matrix-cell--self">'));
        $this->assertSame(10, substr_count($html, '<table class="official-match">'));
        $this->assertSame(
            [[2, 5], [3, 4], [1, 5], [2, 3], [1, 4], [5, 3], [1, 3], [4, 2], [1, 2], [4, 5]],
            $this->officialPairings($html),
        );
        $this->assertSetLabels($html, 3, matchBlocks: 10);
        $this->assertSame(5, substr_count($html, 'official-match-row--pair"'));
    }

    public function test_g5_best_of_five_renders_s1_to_s5(): void
    {
        $html = $this->renderGroupSheet(playerCount: 5, setsToWin: 3);

        $this->assertStringContainsString('data-sheet-kind="g5"', $html);
        $this->assertSetLabels($html, 5, matchBlocks: 10);
        $this->assertSame(
            [[2, 5], [3, 4], [1, 5], [2, 3], [1, 4], [5, 3], [1, 3], [4, 2], [1, 2], [4, 5]],
            $this->officialPairings($html),
        );
    }

    public function test_sheet_shows_display_names_and_assigned_referee(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 2);
        $sheet = app(BuildPrintGroupSheetAction::class)($setup['group']);

        $html = $this->renderSheet($sheet);

        foreach ($sheet->participants as $participant) {
            $this->assertStringContainsString($participant['display_name'], $html);
        }

        $refereeName = $sheet->matches[0]['referee']['display_name'] ?? null;
        $this->assertNotNull($refereeName);
        $this->assertStringContainsString($refereeName, $html);
        $this->assertStringNotContainsString('null', $html);
        $this->assertSame(3, substr_count($html, 'class="official-match-ref"'));
    }

    public function test_unicode_spanish_names_and_ordinal_are_preserved(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition(2);
        $players = $this->createSpanishNamedPlayers();
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        $html = $this->renderSheet(app(BuildPrintGroupSheetAction::class)($group));

        $this->assertStringContainsString('José Muñoz', $html);
        $this->assertStringContainsString('Ángela Gómez', $html);
        $this->assertStringContainsString('Peña Ñúñez', $html);
        $this->assertStringContainsString('á é í ó ú ñ', $html);
        $this->assertStringContainsString('3.º', $html);
        $this->assertStringContainsString('DejaVu Sans', $html);
    }

    public function test_finished_games_keep_blank_operational_cells(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 2);

        $games = Game::query()->where('group_id', $setup['group']->id)->get();
        foreach ($games as $game) {
            $context->finishGameByEntry($game, (int) $game->entry1_id);
        }

        $sheet = app(BuildPrintGroupSheetAction::class)($setup['group']);
        $html = $this->renderSheet($sheet);

        foreach ($sheet->matches as $match) {
            $this->assertArrayNotHasKey('sets', $match);
            $this->assertArrayNotHasKey('winner_entry_id', $match);
            $this->assertArrayNotHasKey('status', $match);
        }

        $this->assertStringContainsString('official-set-cell', $html);
        $this->assertStringContainsString('official-result-cell', $html);
        $this->assertSame(3, substr_count($html, 'matrix-cell--self">'));
        $this->assertMatchesRegularExpression('/class="official-set-cell"><\/td>/', $html);
        $this->assertStringNotContainsString('Ganador', $html);
        $this->assertStringNotContainsString('<td class="print-score-cell"', $html);
        $this->assertStringNotContainsString('game_id', $html);
    }

    public function test_doubles_g5_shows_pair_and_referee_display_names(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createDoublesCompetition(setsToWin: 2);
        $players = $context->createPlayers(10);
        $pairs = [];
        for ($index = 0; $index < 5; $index++) {
            $pairs[] = [$players[$index * 2], $players[$index * 2 + 1]];
        }
        $entries = $context->registerPairs($competition, $pairs);
        $group = $context->createGroup($competition);

        foreach ($entries as $entry) {
            $context->assignEntryToGroupViaApi($group, $entry)->assertCreated();
        }

        $context->generateRoundRobin($group)->assertCreated();

        $sheet = app(BuildPrintGroupSheetAction::class)($group);
        $html = $this->renderSheet($sheet);

        $this->assertSame('g5', $sheet->sheetKind);
        $this->assertStringContainsString('data-sheet-kind="g5"', $html);
        $this->assertStringContainsString('Dobles', $html);

        foreach ($sheet->participants as $participant) {
            $this->assertStringContainsString(' / ', $participant['display_name']);
            foreach (explode(' / ', $participant['display_name']) as $name) {
                $this->assertStringContainsString($name, $html);
            }
        }

        $refereeName = $sheet->matches[0]['referee']['display_name'] ?? null;
        $this->assertNotNull($refereeName);
        $this->assertStringContainsString(' / ', $refereeName);
        foreach (explode(' / ', $refereeName) as $name) {
            $this->assertStringContainsString($name, $html);
        }
        $this->assertSame(10, substr_count($html, '<table class="official-match">'));
    }

    public function test_generic_g2_keeps_previous_layout(): void
    {
        $html = $this->renderGroupSheet(playerCount: 2, setsToWin: 2);

        $this->assertStringContainsString('data-sheet-kind="generic"', $html);
        $this->assertStringContainsString('pdf-sheet--generic', $html);
        $this->assertStringContainsString('print-table', $html);
        $this->assertStringContainsString('Participante A', $html);
        $this->assertStringContainsString('Set 1', $html);
        $this->assertStringContainsString('Set 2', $html);
        $this->assertStringContainsString('Set 3', $html);
        $this->assertStringNotContainsString('class="official-matrix"', $html);
        $this->assertStringNotContainsString('>S1<', $html);
        $this->assertStringContainsString('print-missing-ref', $html);
        $this->assertStringContainsString('—', $html);
        $this->assertStringNotContainsString('>null<', $html);
    }

    public function test_generic_g6_keeps_previous_layout(): void
    {
        $html = $this->renderGroupSheet(playerCount: 6, setsToWin: 2);

        $this->assertStringContainsString('data-sheet-kind="generic"', $html);
        $this->assertStringContainsString('print-table', $html);
        $this->assertStringContainsString('Set 1', $html);
        $this->assertStringNotContainsString('class="official-matrix"', $html);
        $this->assertStringNotContainsString('pdf-sheet--official', $html);
    }

    public function test_missing_referee_renders_em_dash_not_null(): void
    {
        $html = $this->renderGroupSheet(playerCount: 2, setsToWin: 2);

        $this->assertStringContainsString('print-missing-ref', $html);
        $this->assertStringContainsString('—', $html);
        $this->assertStringNotContainsString('>null<', $html);
    }

    public function test_header_includes_blank_handwritten_fields(): void
    {
        $html = $this->renderGroupSheet(playerCount: 3, setsToWin: 2);

        $this->assertStringContainsString('Fecha', $html);
        $this->assertStringContainsString('Mesa', $html);
        $this->assertStringContainsString('Día', $html);
        $this->assertStringContainsString('Hora', $html);
        $this->assertStringContainsString('Clasifican', $html);
        $this->assertStringContainsString('official-header-blank', $html);
    }

    /**
     * @return array{competition: Competition, group: Group}
     */
    private function createSinglesGroup(TournamentTestContext $context, int $playerCount, int $setsToWin): array
    {
        $competition = $context->createCompetition($setsToWin);
        $players = $context->createPlayers($playerCount);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);
        $context->generateRoundRobin($group)->assertCreated();

        return [
            'competition' => $competition,
            'group' => $group,
        ];
    }

    /**
     * @return list<Player>
     */
    private function createSpanishNamedPlayers(): array
    {
        return [
            Player::query()->create(['first_name' => 'José', 'last_name' => 'Muñoz']),
            Player::query()->create(['first_name' => 'Ángela', 'last_name' => 'Gómez']),
            Player::query()->create(['first_name' => 'Peña', 'last_name' => 'Ñúñez']),
        ];
    }

    private function renderGroupSheet(int $playerCount, int $setsToWin): string
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, $playerCount, $setsToWin);

        return $this->renderSheet(app(BuildPrintGroupSheetAction::class)($setup['group']));
    }

    private function renderSheet(object $sheet): string
    {
        return view('pdf.groups.show', [
            'sheet' => $sheet,
            'title' => $sheet->group['name'],
        ])->render();
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function officialPairings(string $html): array
    {
        preg_match_all('/class="official-match-num">([^<]*)</', $html, $matches);

        $numbers = array_map(
            static fn (string $value): int => (int) trim($value),
            $matches[1],
        );
        $pairings = [];

        for ($index = 0; $index < count($numbers); $index += 2) {
            $pairings[] = [$numbers[$index], $numbers[$index + 1] ?? 0];
        }

        return $pairings;
    }

    private function assertSetLabels(string $html, int $bestOf, int $matchBlocks): void
    {
        for ($setNumber = 1; $setNumber <= $bestOf; $setNumber++) {
            $this->assertSame(
                $matchBlocks,
                substr_count($html, '>S'.$setNumber.'<'),
                "Expected {$matchBlocks} labels for S{$setNumber}",
            );
        }

        $this->assertSame(0, substr_count($html, '>S'.($bestOf + 1).'<'));
        $this->assertSame(0, substr_count($html, 'Set '.$bestOf));
    }
}
