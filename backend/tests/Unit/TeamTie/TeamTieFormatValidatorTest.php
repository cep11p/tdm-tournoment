<?php

namespace Tests\Unit\TeamTie;

use App\Enums\TeamTieModality;
use App\Models\TeamTieFormat;
use App\Models\TeamTieFormatSlot;
use App\Support\TeamTie\TeamTieFormatValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TeamTieFormatValidatorTest extends TestCase
{
    public function test_rejects_victories_required_without_majority(): void
    {
        $format = TeamTieFormat::query()->create([
            'name' => 'Invalid',
            'victories_required' => 2,
            'active' => true,
        ]);

        TeamTieFormatSlot::query()->create([
            'team_tie_format_id' => $format->id,
            'slot_order' => 1,
            'modality' => TeamTieModality::Singles,
        ]);

        TeamTieFormatSlot::query()->create([
            'team_tie_format_id' => $format->id,
            'slot_order' => 2,
            'modality' => TeamTieModality::Singles,
        ]);

        TeamTieFormatSlot::query()->create([
            'team_tie_format_id' => $format->id,
            'slot_order' => 3,
            'modality' => TeamTieModality::Singles,
        ]);

        $this->expectException(ValidationException::class);

        TeamTieFormatValidator::ensureValid($format->fresh('slots'));
    }
}
