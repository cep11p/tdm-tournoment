<?php

namespace App\Models;

use App\Enums\GameStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Game extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
            'table_number' => 'integer',
            'bracket_round' => 'integer',
            'bracket_match' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function bracket(): BelongsTo
    {
        return $this->belongsTo(Bracket::class);
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
        return $this->hasMany(GameSet::class)->orderBy('set_number');
    }

    /**
     * @return array{player1: int, player2: int}
     */
    public function setsWonCount(?Collection $sets = null): array
    {
        $sets ??= $this->relationLoaded('sets')
            ? $this->sets
            : $this->sets()->get();

        $player1Wins = 0;
        $player2Wins = 0;

        foreach ($sets as $set) {
            if ($set->player1_score > $set->player2_score) {
                $player1Wins++;
            } elseif ($set->player2_score > $set->player1_score) {
                $player2Wins++;
            }
        }

        return [
            'player1' => $player1Wins,
            'player2' => $player2Wins,
        ];
    }
}
