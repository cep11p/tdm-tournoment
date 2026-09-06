<?php

namespace Tests\Unit\Print;

use App\Support\Print\PrintPdfFilename;
use Tests\TestCase;

class PrintPdfFilenameTest extends TestCase
{
    public function test_group_filename_uses_slug_without_duplicating_prefix(): void
    {
        $this->assertSame('grupo-a.pdf', PrintPdfFilename::group('Grupo A', 12));
        $this->assertSame('grupo-caballeros.pdf', PrintPdfFilename::group('Caballeros', 12));
    }

    public function test_group_filename_falls_back_to_id(): void
    {
        $this->assertSame('grupo-9.pdf', PrintPdfFilename::group('***', 9));
        $this->assertSame('grupo-9.pdf', PrintPdfFilename::group('   ', 9));
    }

    public function test_competition_groups_filename_prefixes_slug(): void
    {
        $this->assertSame(
            'grupos-individual-caballeros.pdf',
            PrintPdfFilename::competitionGroups('Individual Caballeros', 4),
        );
        $this->assertSame(
            'grupos-singles-test.pdf',
            PrintPdfFilename::competitionGroups('Singles Test', 4),
        );
    }

    public function test_competition_groups_filename_falls_back_to_id(): void
    {
        $this->assertSame(
            'competencia-4-grupos.pdf',
            PrintPdfFilename::competitionGroups('@@@', 4),
        );
    }

    public function test_filename_strips_path_characters_and_limits_length(): void
    {
        $long = str_repeat('Grupo ', 40).'A';
        $filename = PrintPdfFilename::group($long, 1);

        $this->assertStringEndsWith('.pdf', $filename);
        $this->assertStringNotContainsString('/', $filename);
        $this->assertStringNotContainsString('\\', $filename);
        $this->assertLessThanOrEqual(PrintPdfFilename::MAX_BASENAME_LENGTH + 4, strlen($filename));
    }
}
