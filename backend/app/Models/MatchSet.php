<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class MatchSet extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'set_number' => 'integer',
            'player1_score' => 'integer',
            'player2_score' => 'integer',
        ];
    }

    public function gameMatch(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }
}
