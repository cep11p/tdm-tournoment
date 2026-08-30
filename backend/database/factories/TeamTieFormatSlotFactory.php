<?php

namespace Database\Factories;

use App\Enums\TeamTieModality;
use App\Models\TeamTieFormat;
use App\Models\TeamTieFormatSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamTieFormatSlot>
 */
class TeamTieFormatSlotFactory extends Factory
{
    protected $model = TeamTieFormatSlot::class;

    public function definition(): array
    {
        return [
            'team_tie_format_id' => TeamTieFormat::factory(),
            'slot_order' => 1,
            'modality' => TeamTieModality::Singles,
        ];
    }
}
