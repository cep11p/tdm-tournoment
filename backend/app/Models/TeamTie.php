<?php

namespace App\Models;

use App\Enums\TeamTieStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamTie extends Model
{
    use HasFactory;

    public const DISPLAY_RELATIONS = [
        'entry1.members.player:id,first_name,last_name,nickname',
        'entry2.members.player:id,first_name,last_name,nickname',
        'winnerEntry.members.player:id,first_name,last_name,nickname',
        'format',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => TeamTieStatus::class,
            'is_bye' => 'boolean',
            'victories_required' => 'integer',
            'group_round' => 'integer',
            'group_match' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function entry1(): BelongsTo
    {
        return $this->belongsTo(CompetitionEntry::class, 'entry1_id');
    }

    public function entry2(): BelongsTo
    {
        return $this->belongsTo(CompetitionEntry::class, 'entry2_id');
    }

    public function winnerEntry(): BelongsTo
    {
        return $this->belongsTo(CompetitionEntry::class, 'winner_entry_id');
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(TeamTieFormat::class, 'team_tie_format_id');
    }

    public function teamTieGames(): HasMany
    {
        return $this->hasMany(TeamTieGame::class)->orderBy('slot_order');
    }
}
