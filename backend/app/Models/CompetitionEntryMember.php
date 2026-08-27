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
        ];
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
