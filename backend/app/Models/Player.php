<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Player extends Model
{
    use HasFactory;

    protected $fillable = [
        'first_name',
        'last_name',
        'nickname',
        'category_id',
        'club_id',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function competitionEntryMembers(): HasMany
    {
        return $this->hasMany(CompetitionEntryMember::class);
    }

    public function groupEntries(): HasManyThrough
    {
        return $this->hasManyThrough(
            GroupEntry::class,
            CompetitionEntryMember::class,
            'player_id',
            'competition_entry_id',
            'id',
            'competition_entry_id',
        );
    }

    public function gamesCount(): int
    {
        $entryIds = $this->competitionEntryMembers()->pluck('competition_entry_id');

        if ($entryIds->isEmpty()) {
            return 0;
        }

        return Game::query()
            ->where(function ($query) use ($entryIds): void {
                $query->whereIn('entry1_id', $entryIds)
                    ->orWhereIn('entry2_id', $entryIds);
            })
            ->count();
    }
}
