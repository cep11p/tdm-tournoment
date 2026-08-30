<?php

namespace Database\Factories;

use App\Enums\TeamTieStatus;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\Group;
use App\Models\TeamTie;
use App\Models\TeamTieFormat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamTie>
 */
class TeamTieFactory extends Factory
{
    protected $model = TeamTie::class;

    public function definition(): array
    {
        return [
            'competition_id' => Competition::factory(),
            'group_id' => Group::factory(),
            'entry1_id' => CompetitionEntry::factory(),
            'entry2_id' => CompetitionEntry::factory(),
            'winner_entry_id' => null,
            'team_tie_format_id' => TeamTieFormat::factory(),
            'victories_required' => 3,
            'format_name' => 'Copa 5',
            'status' => TeamTieStatus::Pending,
            'is_bye' => false,
            'group_round' => 1,
            'group_match' => 1,
            'finished_at' => null,
        ];
    }
}
