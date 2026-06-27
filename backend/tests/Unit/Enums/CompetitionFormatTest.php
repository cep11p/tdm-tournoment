<?php

namespace Tests\Unit\Enums;

use App\Enums\CompetitionFormat;
use PHPUnit\Framework\TestCase;

class CompetitionFormatTest extends TestCase
{
    public function test_manual_normalizes_to_groups_knockout(): void
    {
        $this->assertSame(
            CompetitionFormat::GroupsKnockout,
            CompetitionFormat::Manual->normalized()
        );
    }

    public function test_groups_knockout_has_group_stage(): void
    {
        $this->assertTrue(CompetitionFormat::GroupsKnockout->hasGroupStage());
        $this->assertTrue(CompetitionFormat::Manual->hasGroupStage());
    }

    public function test_knockout_direct_does_not_have_group_stage(): void
    {
        $this->assertFalse(CompetitionFormat::KnockoutDirect->hasGroupStage());
        $this->assertTrue(CompetitionFormat::KnockoutDirect->isKnockoutDirect());
    }

    public function test_label_returns_human_readable_name(): void
    {
        $this->assertSame(
            'Fase de grupos + eliminatoria',
            CompetitionFormat::GroupsKnockout->label()
        );
        $this->assertSame(
            'Eliminación directa',
            CompetitionFormat::KnockoutDirect->label()
        );
        $this->assertSame(
            'Fase de grupos + eliminatoria',
            CompetitionFormat::Manual->label()
        );
    }
}
