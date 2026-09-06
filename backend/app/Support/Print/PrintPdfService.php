<?php

namespace App\Support\Print;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Carbon\Carbon;
use Dompdf\Canvas;
use Dompdf\FontMetrics;
use Illuminate\Http\Response;

final class PrintPdfService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function render(
        string $view,
        array $data,
        string $filename,
        string $paper = 'a4',
        string $orientation = 'portrait',
        bool $download = false,
    ): Response {
        $this->ensureFontCacheDirectory();

        $pdf = Pdf::loadView($view, $data)
            ->setPaper($paper, $orientation)
            ->setOption([
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'defaultFont' => 'DejaVu Sans',
            ]);

        $pdf->render();
        $this->paintFooter($pdf);

        return $download
            ? $pdf->download($filename)
            : $pdf->stream($filename);
    }

    private function paintFooter(DomPdfWrapper $pdf): void
    {
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $generatedAt = $this->generatedAtLabel();

        $canvas->page_script(function (
            int $pageNumber,
            int $pageCount,
            Canvas $canvas,
            FontMetrics $fontMetrics,
        ) use ($font, $generatedAt): void {
            $size = 8;
            $y = $canvas->get_height() - 22;
            $gray = [0.25, 0.25, 0.25];

            $canvas->text(
                36,
                $y,
                sprintf('Página %d de %d', $pageNumber, $pageCount),
                $font,
                $size,
                $gray,
            );

            $textWidth = $fontMetrics->getTextWidth($generatedAt, $font, $size);
            $canvas->text(
                $canvas->get_width() - 36 - $textWidth,
                $y,
                $generatedAt,
                $font,
                $size,
                $gray,
            );
        });
    }

    private function generatedAtLabel(): string
    {
        return 'Generado el '.Carbon::now(config('app.timezone'))->format('d/m/Y H:i');
    }

    private function ensureFontCacheDirectory(): void
    {
        $directory = storage_path('fonts');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
