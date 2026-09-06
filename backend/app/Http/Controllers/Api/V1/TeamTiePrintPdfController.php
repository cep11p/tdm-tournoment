<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\TeamTie\BuildPrintTeamTieAction;
use App\Http\Controllers\Controller;
use App\Models\TeamTie;
use App\Support\Print\PrintPdfFilename;
use App\Support\Print\PrintPdfService;
use App\Support\Print\PrintPresentation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TeamTiePrintPdfController extends Controller
{
    public function __invoke(
        Request $request,
        TeamTie $teamTie,
        BuildPrintTeamTieAction $buildPrintTeamTie,
        PrintPdfService $printPdf,
    ): Response {
        $sheet = $buildPrintTeamTie($teamTie);
        $title = PrintPresentation::teamTieMatchupLabel($sheet);

        return $printPdf->render(
            view: 'pdf.team-ties.show',
            data: [
                'sheet' => $sheet,
                'title' => $title !== '' ? $title : 'Planilla de enfrentamiento',
            ],
            filename: PrintPdfFilename::teamTie(
                side1Name: PrintPresentation::teamTieSideName($sheet->side1, ''),
                side2Name: $sheet->side2 === null
                    ? null
                    : PrintPresentation::teamTieSideName($sheet->side2, ''),
                isBye: (bool) ($sheet->teamTie['is_bye'] ?? false),
                teamTieId: (int) $sheet->teamTie['id'],
            ),
            paper: 'a4',
            orientation: 'portrait',
            download: PrintPresentation::wantsDownload($request),
        );
    }
}
