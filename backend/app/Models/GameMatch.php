<?php

namespace App\Models;

use App\Enums\MatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class GameMatch extends Model
{
    use HasFactory;

    protected $table = 'matches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => MatchStatus::class,
        ];
    }

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
        return $this->hasMany(MatchSet::class, 'match_id');
    }
}
