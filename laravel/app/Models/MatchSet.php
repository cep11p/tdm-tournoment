<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchSet extends Model
{
    protected $fillable = [
        'match_id',
        'set_number',
        'player1_score',
        'player2_score',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(Match::class);
    }

    public function getWinnerSlotAttribute(): ?string
    {
        if ($this->player1_score > $this->player2_score) {
            return 'player1';
        }

        if ($this->player2_score > $this->player1_score) {
            return 'player2';
        }

        return null;
    }
}
