<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Group\BuildCompetitionGroupsPrintAction;
use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Support\Print\PrintPdfFilename;
use App\Support\Print\PrintPdfService;
use App\Support\Print\PrintPresentation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CompetitionGroupsPrintPdfController extends Controller
{
    public function __invoke(
        Request $request,
        Competition $competition,
        BuildCompetitionGroupsPrintAction $buildCompetitionGroupsPrint,
        PrintPdfService $printPdf,
    ): Response {
        $payload = $buildCompetitionGroupsPrint($competition);

        return $printPdf->render(
            view: 'pdf.groups.all',
            data: [
                'payload' => $payload,
                'title' => $payload->competition['name'] !== ''
                    ? $payload->competition['name']
                    : 'Planillas de grupos',
            ],
            filename: PrintPdfFilename::competitionGroups(
                $payload->competition['name'],
                $payload->competition['id'],
            ),
            paper: 'a4',
            orientation: PrintPresentation::orientationFromSheets($payload->sheets),
            download: PrintPresentation::wantsDownload($request),
        );
    }
}
