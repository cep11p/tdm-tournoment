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
    public function test_best_of_three_renders_three_set_columns(): void
    {
        $html = $this->renderGroupSheet(playerCount: 3, setsToWin: 2);

        $this->assertSame(1, substr_count($html, 'Set 1'));
        $this->assertSame(1, substr_count($html, 'Set 2'));
        $this->assertSame(1, substr_count($html, 'Set 3'));
        $this->assertSame(0, substr_count($html, 'Set 4'));
        $this->assertSame(0, substr_count($html, 'Set 5'));
    }

    public function test_best_of_five_renders_five_set_columns(): void
    {
        $html = $this->renderGroupSheet(playerCount: 3, setsToWin: 3);

        $this->assertSame(1, substr_count($html, 'Set 1'));
        $this->assertSame(1, substr_count($html, 'Set 5'));
        $this->assertSame(0, substr_count($html, 'Set 6'));
        $this->assertSame(0, substr_count($html, 'Set 7'));
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

    public function test_finished_games_keep_blank_score_cells(): void
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

        $this->assertStringContainsString('print-score-cell', $html);
        $this->assertStringNotContainsString('Ganador', $html);
    }

    public function test_missing_referee_renders_em_dash_not_null(): void
    {
        $html = $this->renderGroupSheet(playerCount: 2, setsToWin: 2);

        $this->assertStringContainsString('print-missing-ref', $html);
        $this->assertStringContainsString('—', $html);
        $this->assertStringNotContainsString('>null<', $html);
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
}
