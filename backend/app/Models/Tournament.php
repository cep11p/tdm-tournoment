<?php

namespace App\Models;

use App\Enums\TournamentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tournament extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => TournamentStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public function competitions(): HasMany
    {
        return $this->hasMany(Competition::class);
    }

    public function playingTables(): HasMany
    {
        return $this->hasMany(PlayingTable::class)
            ->orderBy('sort_order')
            ->orderBy('number');
    }
}
