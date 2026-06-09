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

    public function gamesAsPlayer1(): HasMany
    {
        return $this->hasMany(Game::class, 'player1_id');
    }

    public function gamesAsPlayer2(): HasMany
    {
        return $this->hasMany(Game::class, 'player2_id');
    }

    public function wonGames(): HasMany
    {
        return $this->hasMany(Game::class, 'winner_id');
    }
}
