<?php

namespace Tests\Feature\Print;

use App\Actions\Group\BuildPrintGroupSheetAction;
use App\Models\Competition;
use App\Models\Game;
use App\Models\Group;
use App\Support\Print\PrintPdfFilename;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class GroupPrintPdfTest extends TestCase
{
    public function test_group_pdf_is_public_and_returns_pdf(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 2);

        $response = $this->get($context->apiUrl("groups/{$setup['group']->id}/print/pdf"));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString(
            PrintPdfFilename::group($setup['group']->name, $setup['group']->id),
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_download_query_uses_attachment_disposition(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 2);
        $filename = PrintPdfFilename::group($setup['group']->name, $setup['group']->id);

        $response = $this->get($context->apiUrl("groups/{$setup['group']->id}/print/pdf?download=1"));

        $response->assertOk();
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString($filename, $disposition);
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_best_of_five_pdf_is_generated_successfully(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 3);

        $response = $this->get($context->apiUrl("groups/{$setup['group']->id}/print/pdf"));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame(5, app(BuildPrintGroupSheetAction::class)($setup['group'])->bestOf);
    }

    public function test_g4_g5_and_generic_pdfs_are_generated_successfully(): void
    {
        $context = $this->tournamentContext();

        foreach ([
            ['players' => 2, 'setsToWin' => 2],
            ['players' => 4, 'setsToWin' => 2],
            ['players' => 5, 'setsToWin' => 2],
            ['players' => 5, 'setsToWin' => 3],
            ['players' => 6, 'setsToWin' => 2],
        ] as $case) {
            $setup = $this->createSinglesGroup($context, playerCount: $case['players'], setsToWin: $case['setsToWin']);
            $response = $this->get($context->apiUrl("groups/{$setup['group']->id}/print/pdf"));

            $response->assertOk();
            $this->assertStringStartsWith('%PDF', $response->getContent());
            $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        }
    }

    public function test_json_print_contract_is_unchanged(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 2);

        $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
            ->assertOk()
            ->assertJsonPath('data.group.id', $setup['group']->id)
            ->assertJsonPath('data.best_of', 3);
    }

    public function test_pdf_uses_the_same_referees_as_json_payload(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 2);

        $jsonReferees = collect(
            $this->getJson($context->apiUrl("groups/{$setup['group']->id}/print"))
                ->assertOk()
                ->json('data.matches'),
        )->pluck('referee.display_name')->all();

        $sheet = app(BuildPrintGroupSheetAction::class)($setup['group']);
        $html = view('pdf.groups.show', [
            'sheet' => $sheet,
            'title' => $sheet->group['name'],
        ])->render();

        $this->assertSame(
            $jsonReferees,
            collect($sheet->matches)->pluck('referee.display_name')->all(),
        );

        foreach ($jsonReferees as $name) {
            $this->assertNotNull($name);
            $this->assertStringContainsString($name, $html);
        }
    }

    public function test_team_group_returns_the_same_unprocessable_as_json(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 4, 4);
        $group = $context->createGroupWithEntries($competition, $entries);

        $this->getJson($context->apiUrl("groups/{$group->id}/print/pdf"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group'])
            ->assertJsonPath(
                'errors.group.0',
                BuildPrintGroupSheetAction::TEAM_NOT_AVAILABLE_MESSAGE,
            );
    }

    public function test_group_without_fixture_returns_the_same_unprocessable_as_json(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();
        $players = $context->createPlayers(3);
        $context->registerPlayers($competition, $players);
        $group = $context->createGroupWithPlayers($competition, $players);

        $this->getJson($context->apiUrl("groups/{$group->id}/print/pdf"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group'])
            ->assertJsonPath(
                'errors.group.0',
                BuildPrintGroupSheetAction::MISSING_FIXTURE_MESSAGE,
            );
    }

    public function test_missing_group_returns_not_found(): void
    {
        $context = $this->tournamentContext();

        $this->get($context->apiUrl('groups/999999/print/pdf'))
            ->assertNotFound();
    }

    public function test_finished_group_pdf_is_still_an_operational_sheet(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesGroup($context, playerCount: 3, setsToWin: 2);

        $games = Game::query()->where('group_id', $setup['group']->id)->get();
        foreach ($games as $game) {
            $context->finishGameByEntry($game, (int) $game->entry1_id);
        }

        $response = $this->get($context->apiUrl("groups/{$setup['group']->id}/print/pdf"));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $sheet = app(BuildPrintGroupSheetAction::class)($setup['group']);
        foreach ($sheet->matches as $match) {
            $this->assertArrayNotHasKey('sets', $match);
            $this->assertArrayNotHasKey('status', $match);
        }
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
}
