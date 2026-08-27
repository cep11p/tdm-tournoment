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

    public function entries(): HasMany
    {
        return $this->hasMany(GroupManualTiebreakEntry::class)->orderBy('position');
    }

    /**
     * @return array<int, int>
     */
    public function orderedCompetitionEntryIds(): array
    {
        $entries = $this->relationLoaded('entries')
            ? $this->entries
            : $this->entries()->orderBy('position')->get();

        return $entries
            ->pluck('competition_entry_id')
            ->map(fn (int $entryId): int => (int) $entryId)
            ->all();
    }
}
