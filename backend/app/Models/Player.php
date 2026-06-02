<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function matchesAsPlayer1(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'player1_id');
    }

    public function matchesAsPlayer2(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'player2_id');
    }

    public function wonMatches(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'winner_id');
    }
}
