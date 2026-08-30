<?php

namespace Database\Seeders;

use App\Enums\TeamTieModality;
use App\Models\TeamTieFormat;
use App\Models\TeamTieFormatSlot;
use Illuminate\Database\Seeder;

class TeamTieFormatSeeder extends Seeder
{
    public function run(): void
    {
        if (TeamTieFormat::query()->where('name', 'Copa 5')->exists()) {
            return;
        }

        $format = TeamTieFormat::query()->create([
            'name' => 'Copa 5',
            'description' => 'Formato clásico de 5 partidos: 4 singles y 1 dobles.',
            'victories_required' => 3,
            'active' => true,
        ]);

        $modalities = [
            TeamTieModality::Singles,
            TeamTieModality::Singles,
            TeamTieModality::Doubles,
            TeamTieModality::Singles,
            TeamTieModality::Singles,
        ];

        foreach ($modalities as $index => $modality) {
            TeamTieFormatSlot::query()->create([
                'team_tie_format_id' => $format->id,
                'slot_order' => $index + 1,
                'modality' => $modality,
            ]);
        }
    }
}
