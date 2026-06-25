<?php

namespace App\Models;

use App\Enums\ManualTiebreakReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupManualTiebreak extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reason' => ManualTiebreakReason::class,
            'applied_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(GroupManualTiebreakPlayer::class)->orderBy('position');
    }

    /**
     * @return array<int, int>
     */
    public function orderedPlayerIds(): array
    {
        $players = $this->relationLoaded('players')
            ? $this->players
            : $this->players()->orderBy('position')->get();

        return $players
            ->pluck('player_id')
            ->map(fn (int $playerId): int => (int) $playerId)
            ->all();
    }
}
