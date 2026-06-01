<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Match extends Model
{
    protected $fillable = [
        'competition_id',
        'player1_id',
        'player2_id',
        'winner_id',
        'status',
        'round',
    ];

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function player1(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player1_id');
    }

    public function player2(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player2_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'winner_id');
    }

    public function sets(): HasMany
    {
        return $this->hasMany(MatchSet::class)->orderBy('set_number');
    }

    public function isFinished(): bool
    {
        return $this->status === 'finished';
    }
}
