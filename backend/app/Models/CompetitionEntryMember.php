<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitionEntryMember extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'member_order' => 'integer',
            'checked_in_at' => 'datetime',
        ];
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    public function competitionEntry(): BelongsTo
    {
        return $this->belongsTo(CompetitionEntry::class);
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
