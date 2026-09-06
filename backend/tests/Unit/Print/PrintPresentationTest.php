<?php

namespace Tests\Unit\Print;

use App\Data\Group\PrintGroupSheetData;
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
}
