<?php

namespace Tests\Unit\Print;

use App\Data\Group\PrintGroupSheetData;
use App\Data\TeamTie\PrintTeamTieData;
use App\Support\Print\PrintPresentation;
use Illuminate\Http\Request;
use Tests\TestCase;

class PrintPresentationTest extends TestCase
{
    public function test_set_columns_follow_best_of(): void
    {
        $this->assertSame([1], PrintPresentation::setColumns(1));
        $this->assertSame([1, 2, 3], PrintPresentation::setColumns(3));
        $this->assertSame([1, 2, 3, 4, 5], PrintPresentation::setColumns(5));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], PrintPresentation::setColumns(7));
    }

    public function test_set_columns_default_to_best_of_three_when_missing(): void
    {
        $this->assertSame([1, 2, 3], PrintPresentation::setColumns(null));
        $this->assertSame([1, 2, 3], PrintPresentation::setColumns(0));
    }

    public function test_orientation_matches_frontend_threshold(): void
    {
        $this->assertSame('portrait', PrintPresentation::orientation(1));
        $this->assertSame('portrait', PrintPresentation::orientation(3));
        $this->assertSame('landscape', PrintPresentation::orientation(5));
        $this->assertSame('landscape', PrintPresentation::orientation(7));
    }

    public function test_orientation_from_sheets_uses_maximum_best_of(): void
    {
        $portrait = $this->sheet(bestOf: 3);
        $landscape = $this->sheet(bestOf: 5);

        $this->assertSame('portrait', PrintPresentation::orientationFromSheets([$portrait, $portrait]));
        $this->assertSame('landscape', PrintPresentation::orientationFromSheets([$portrait, $landscape]));
    }

    public function test_competition_type_labels(): void
    {
        $this->assertSame('Singles', PrintPresentation::competitionTypeLabel('singles'));
        $this->assertSame('Dobles', PrintPresentation::competitionTypeLabel('doubles'));
        $this->assertSame('Equipos', PrintPresentation::competitionTypeLabel('team'));
        $this->assertSame('—', PrintPresentation::competitionTypeLabel(null));
    }

    public function test_wants_download_accepts_simple_truthy_values(): void
    {
        $this->assertFalse(PrintPresentation::wantsDownload(Request::create('/print/pdf')));
        $this->assertFalse(PrintPresentation::wantsDownload(Request::create('/print/pdf?download=0')));
        $this->assertTrue(PrintPresentation::wantsDownload(Request::create('/print/pdf?download=1')));
        $this->assertTrue(PrintPresentation::wantsDownload(Request::create('/print/pdf?download=true')));
    }

    public function test_team_tie_lineup_falls_back_to_por_definir(): void
    {
        $this->assertSame('Por definir', PrintPresentation::teamTieLineupLabel(null));
        $this->assertSame('Por definir', PrintPresentation::teamTieLineupLabel(['players' => []]));
        $this->assertSame('Por definir', PrintPresentation::teamTieLineupLabel([
            'players' => [['id' => 1, 'name' => '   ']],
        ]));
    }

    public function test_team_tie_lineup_joins_names_in_payload_order(): void
    {
        $this->assertSame(
            'Carlos Perez',
            PrintPresentation::teamTieLineupLabel([
                'players' => [['id' => 1, 'name' => 'Carlos Perez']],
            ]),
        );
        $this->assertSame(
            'Carlos Perez / Martin Castro',
            PrintPresentation::teamTieLineupLabel([
                'players' => [
                    ['id' => 2, 'name' => 'Carlos Perez'],
                    ['id' => 1, 'name' => 'Martin Castro'],
                ],
            ]),
        );
    }

    public function test_team_tie_rubber_status_labels(): void
    {
        $this->assertSame('Pendiente', PrintPresentation::teamTieRubberStatusLabel(['status' => 'pending']));
        $this->assertSame('En juego', PrintPresentation::teamTieRubberStatusLabel(['status' => 'in_progress']));
        $this->assertSame('No necesario', PrintPresentation::teamTieRubberStatusLabel(['status' => 'not_needed']));
        $this->assertSame('', PrintPresentation::teamTieRubberStatusLabel([
            'status' => 'finished',
            'official' => true,
        ]));
        $this->assertSame('No oficial', PrintPresentation::teamTieRubberStatusLabel([
            'status' => 'finished',
            'official' => false,
        ]));
        $this->assertSame('Pase directo', PrintPresentation::teamTieByeLabel());
    }

    public function test_team_tie_should_show_score(): void
    {
        $this->assertFalse(PrintPresentation::teamTieShouldShowScore($this->teamTieSheet()));
        $this->assertFalse(PrintPresentation::teamTieShouldShowScore($this->teamTieSheet(
            status: 'pending',
            isBye: true,
            side1Score: 1,
        )));
        $this->assertTrue(PrintPresentation::teamTieShouldShowScore($this->teamTieSheet(status: 'in_progress')));
        $this->assertTrue(PrintPresentation::teamTieShouldShowScore($this->teamTieSheet(status: 'finished')));
        $this->assertTrue(PrintPresentation::teamTieShouldShowScore($this->teamTieSheet(
            status: 'pending',
            side1Score: 1,
        )));
    }

    public function test_team_tie_matchup_label(): void
    {
        $this->assertSame('Andes vs Patagonia', PrintPresentation::teamTieMatchupLabel($this->teamTieSheet()));
        $this->assertSame('Andes', PrintPresentation::teamTieMatchupLabel($this->teamTieSheet(isBye: true)));
    }

    private function sheet(int $bestOf): PrintGroupSheetData
    {
        return new PrintGroupSheetData(
            tournament: ['id' => 1, 'name' => 'Torneo'],
            competition: ['id' => 1, 'name' => 'Comp', 'type' => 'singles'],
            group: ['id' => 1, 'name' => 'Grupo A'],
            bestOf: $bestOf,
            setsToWin: intdiv($bestOf, 2) + 1,
            pointsPerSet: 11,
            qualifiedPerGroup: 2,
            participants: [],
            matches: [],
        );
    }

    private function teamTieSheet(
        string $status = 'pending',
        bool $isBye = false,
        int $side1Score = 0,
        int $side2Score = 0,
    ): PrintTeamTieData {
        return new PrintTeamTieData(
            tournament: ['id' => 1, 'name' => 'Torneo'],
            competition: ['id' => 1, 'name' => 'Comp', 'type' => 'team'],
            teamTie: [
                'id' => 1,
                'context_label' => 'Grupo A · Ronda 1',
                'status' => $status,
                'is_bye' => $isBye,
                'victories_required' => 3,
            ],
            side1: ['competition_entry_id' => 1, 'display_name' => 'Andes'],
            side2: $isBye ? null : ['competition_entry_id' => 2, 'display_name' => 'Patagonia'],
            score: ['side1' => $side1Score, 'side2' => $side2Score],
            winner: null,
            format: ['name' => 'Copa 5', 'slots_count' => $isBye ? 0 : 5],
            rubbers: [],
        );
    }
}
