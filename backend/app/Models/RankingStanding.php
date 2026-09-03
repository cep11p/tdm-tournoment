<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RankingStanding extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'points_total' => 'integer',
            'events_count' => 'integer',
        ];
    }

    public function ranking(): BelongsTo
    {
        return $this->belongsTo(Ranking::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
