<?php

namespace App\Models;

use App\Enums\CompetitionEntryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompetitionEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CompetitionEntryStatus::class,
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(CompetitionEntryMember::class);
    }

    public function groupEntries(): HasMany
    {
        return $this->hasMany(GroupEntry::class);
    }

    public function singlesMember(): ?CompetitionEntryMember
    {
        $members = $this->relationLoaded('members')
            ? $this->members
            : $this->members()->get();

        return $members->firstWhere('member_order', 1) ?? $members->first();
    }

    public function singlesPlayer(): ?Player
    {
        $member = $this->singlesMember();

        if ($member === null) {
            return null;
        }

        if ($member->relationLoaded('player')) {
            return $member->player;
        }

        return $member->player()->first();
    }

    public function singlesPlayerId(): ?int
    {
        $member = $this->singlesMember();

        return $member !== null ? (int) $member->player_id : null;
    }
}
