<?php

namespace Tests\Feature\Print;

use App\Actions\Group\BuildCompetitionGroupsPrintAction;
use App\Actions\Group\BuildPrintGroupSheetAction;
use App\Models\Competition;
use App\Models\Group;
use App\Support\Print\PrintPdfFilename;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class CompetitionGroupsPrintPdfTest extends TestCase
{
    public function test_all_groups_pdf_is_public_and_returns_pdf(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesCompetitionWithGroups($context, playerCountPerGroup: 3, setsToWin: 2);

        $started = hrtime(true);
        $response = $this->get($context->apiUrl("competitions/{$setup['competition']->id}/groups/print/pdf"));
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString(
            PrintPdfFilename::competitionGroups($setup['competition']->name, $setup['competition']->id),
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertLessThan(30_000, $elapsedMs, 'all-groups PDF exceeded 30s');
    }

    public function test_all_groups_download_query_uses_attachment(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesCompetitionWithGroups($context, playerCountPerGroup: 3, setsToWin: 2);

        $response = $this->get(
            $context->apiUrl("competitions/{$setup['competition']->id}/groups/print/pdf?download=1"),
        );

        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_all_groups_blade_renders_each_group_and_page_break(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesCompetitionWithGroups($context, playerCountPerGroup: 3, setsToWin: 2);
        $payload = app(BuildCompetitionGroupsPrintAction::class)($setup['competition']);

        $html = view('pdf.groups.all', [
            'payload' => $payload,
            'title' => $payload->competition['name'],
        ])->render();

        $this->assertStringContainsString('Grupo A', $html);
        $this->assertStringContainsString('Grupo B', $html);
        $this->assertSame(1, substr_count($html, 'class="pdf-sheet pdf-sheet--break"'));
        $this->assertStringContainsString('page-break-after: always', $html);
    }

    public function test_json_all_groups_contract_is_unchanged(): void
    {
        $context = $this->tournamentContext();
        $setup = $this->createSinglesCompetitionWithGroups($context, playerCountPerGroup: 3, setsToWin: 2);

        $this->getJson($context->apiUrl("competitions/{$setup['competition']->id}/groups/print"))
            ->assertOk()
            ->assertJsonPath('data.groups_count', 2)
            ->assertJsonPath('data.sheets.0.group.name', 'Grupo A');
    }

    public function test_team_competition_returns_the_same_unprocessable_as_json(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createTeamCompetition(4);
        $entries = $context->registerTeams($competition, 4, 4);
        $context->createGroupWithEntries($competition, $entries);

        $this->getJson($context->apiUrl("competitions/{$competition->id}/groups/print/pdf"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath(
                'errors.competition.0',
                BuildPrintGroupSheetAction::TEAM_NOT_AVAILABLE_MESSAGE,
            );
    }

    public function test_competition_without_groups_returns_the_same_unprocessable_as_json(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition();

        $this->getJson($context->apiUrl("competitions/{$competition->id}/groups/print/pdf"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath(
                'errors.competition.0',
                BuildCompetitionGroupsPrintAction::WITHOUT_GROUPS_MESSAGE,
            );
    }

    public function test_missing_fixture_returns_the_same_unprocessable_as_json(): void
    {
        $context = $this->tournamentContext();
        $competition = $context->createCompetition(2);
        $players = $context->createPlayers(6);
        $context->registerPlayers($competition, $players);

        $groupA = $context->createGroupWithPlayers($competition, array_slice($players, 0, 3), 'Grupo A');
        $groupB = $context->createGroupWithPlayers($competition, array_slice($players, 3, 3), 'Grupo B');
        $context->generateRoundRobin($groupA)->assertCreated();

        $this->get($context->apiUrl("competitions/{$competition->id}/groups/print/pdf"))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['competition'])
            ->assertJsonPath(
                'errors.competition.0',
                BuildCompetitionGroupsPrintAction::GROUPS_WITHOUT_SCHEDULE_MESSAGE,
            )
            ->assertJsonPath('groups_without_schedule.0.id', $groupB->id)
            ->assertJsonMissingPath('data.sheets');
    }

    /**
     * @return array{competition: Competition, groupA: Group, groupB: Group}
     */
    private function createSinglesCompetitionWithGroups(
        TournamentTestContext $context,
        int $playerCountPerGroup,
        int $setsToWin,
    ): array {
        $competition = $context->createCompetition($setsToWin);
        $players = $context->createPlayers($playerCountPerGroup * 2);
        $context->registerPlayers($competition, $players);

        $groupA = $context->createGroupWithPlayers(
            $competition,
            array_slice($players, 0, $playerCountPerGroup),
            'Grupo A',
        );
        $groupB = $context->createGroupWithPlayers(
            $competition,
            array_slice($players, $playerCountPerGroup, $playerCountPerGroup),
            'Grupo B',
        );

        $context->generateRoundRobin($groupA)->assertCreated();
        $context->generateRoundRobin($groupB)->assertCreated();

        return [
            'competition' => $competition,
            'groupA' => $groupA,
            'groupB' => $groupB,
        ];
    }
}
