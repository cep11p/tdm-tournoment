<?php

namespace Tests\Feature\Print;

use App\Actions\TeamTie\BuildPrintTeamTieAction;
use App\Enums\CompetitionFormat;
use App\Enums\TeamTieModality;
use App\Models\CompetitionEntry;
use App\Models\CompetitionEntryMember;
use App\Models\Game;
use App\Models\TeamTie;
use App\Models\TeamTieFormat;
use App\Models\TeamTieGame;
use App\Support\Print\PrintPdfFilename;
use App\Support\Print\PrintPresentation;
use Tests\Support\TournamentTestContext;
use Tests\TestCase;

class TeamTiePrintPdfTest extends TestCase
{
    public function test_team_tie_pdf_is_public_and_returns_pdf(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();
        $filename = $this->filenameFor($teamTie);

        $response = $this->get($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print/pdf"));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString($filename, (string) $response->headers->get('Content-Disposition'));
    }

    public function test_download_query_uses_attachment_disposition(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();
        $filename = $this->filenameFor($teamTie);

        $response = $this->get($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print/pdf?download=1"));

        $response->assertOk();
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString($filename, $disposition);
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_print_pdf_endpoint_remains_public(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();

        $this->get($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print/pdf"))
            ->assertOk();
    }

    public function test_json_print_contract_is_unchanged(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $this->lineupRubber($teamTie, $entries, 1);

        $payload = $this->getJson($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print"))
            ->assertOk()
            ->assertJsonPath('data.team_tie.id', $teamTie->id)
            ->assertJsonPath('data.format.name', 'Copa 5')
            ->json('data');

        $this->assertArrayNotHasKey('best_of', $payload);
        $this->assertArrayNotHasKey('sets', $payload);

        $rubber = $payload['rubbers'][0];
        $this->assertArrayNotHasKey('best_of', $rubber);
        $this->assertArrayNotHasKey('sets', $rubber);
        $this->assertArrayNotHasKey('sets_won', $rubber);
        $this->assertArrayNotHasKey('game_id', $rubber);
        $this->assertArrayNotHasKey('player1', $rubber);
        $this->assertArrayNotHasKey('player2', $rubber);
    }

    public function test_missing_team_tie_returns_not_found(): void
    {
        $this->get($this->tournamentContext()->apiUrl('team-ties/999999/print/pdf'))
            ->assertNotFound();
    }

    public function test_incomplete_lineup_still_returns_pdf(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $this->lineupRubber($teamTie, $entries, 1);

        $response = $this->get($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print/pdf"));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_empty_games_still_returns_pdf(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();
        $this->deleteRubbers($teamTie);

        $response = $this->get($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print/pdf"));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_snapshot_format_still_returns_pdf_after_live_rename(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();
        $teamTie->update(['format_name' => 'Copa Histórica']);
        TeamTieFormat::query()->whereKey($teamTie->team_tie_format_id)->update(['name' => 'Copa Nueva']);

        $this->get($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print/pdf"))
            ->assertOk();
    }

    public function test_bye_pdf_is_generated_successfully(): void
    {
        $teamTie = $this->createByeTeamTie();

        $response = $this->get($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print/pdf"));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString(
            $this->filenameFor($teamTie->fresh()),
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_pending_copa_5_pdf_stays_within_loose_budget(): void
    {
        [$teamTie] = $this->createScheduledTeamTie();

        $started = hrtime(true);
        $response = $this->get($this->tournamentContext()->apiUrl("team-ties/{$teamTie->id}/print/pdf"));
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        $response->assertOk();
        $this->assertLessThan(5_000, $elapsedMs, 'pending Copa5 PDF exceeded 5s');
    }

    public function test_finished_copa_5_pdf_stays_within_loose_budget(): void
    {
        [$teamTie, $entries] = $this->createScheduledTeamTie();
        $context = $this->tournamentContext();

        $this->winRubber($context, $teamTie, $entries, 1, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 2, (int) $teamTie->entry1_id);
        $this->winRubber($context, $teamTie, $entries, 3, (int) $teamTie->entry1_id);

        $started = hrtime(true);
        $response = $this->get($context->apiUrl("team-ties/{$teamTie->id}/print/pdf"));
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertLessThan(5_000, $elapsedMs, 'finished Copa5 PDF exceeded 5s');
    }

    private function filenameFor(TeamTie $teamTie): string
    {
        $sheet = app(BuildPrintTeamTieAction::class)($teamTie);

        return PrintPdfFilename::teamTie(
            side1Name: PrintPresentation::teamTieSideName($sheet->side1, ''),
            side2Name: $sheet->side2 === null
                ? null
                : PrintPresentation::teamTieSideName($sheet->side2, ''),
            isBye: (bool) ($sheet->teamTie['is_bye'] ?? false),
            teamTieId: (int) $sheet->teamTie['id'],
        );
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
