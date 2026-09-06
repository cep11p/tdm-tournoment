<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Group\BuildPrintGroupSheetAction;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Support\Print\PrintPdfFilename;
use App\Support\Print\PrintPdfService;
use App\Support\Print\PrintPresentation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GroupPrintPdfController extends Controller
{
    public function __invoke(
        Request $request,
        Group $group,
        BuildPrintGroupSheetAction $buildPrintGroupSheet,
        PrintPdfService $printPdf,
    ): Response {
        $sheet = $buildPrintGroupSheet($group);

        return $printPdf->render(
            view: 'pdf.groups.show',
            data: [
                'sheet' => $sheet,
                'title' => $sheet->group['name'] !== '' ? $sheet->group['name'] : 'Planilla de grupo',
            ],
            filename: PrintPdfFilename::group($sheet->group['name'], $sheet->group['id']),
            paper: 'a4',
            orientation: PrintPresentation::orientation($sheet->bestOf),
            download: PrintPresentation::wantsDownload($request),
        );
    }
}
