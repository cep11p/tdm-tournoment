<?php

namespace App\Models;

use App\Enums\GroupPlayerStatus;
use App\Enums\GroupPlayerStatusReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GroupPlayerStatus::class,
            'status_reason' => GroupPlayerStatusReason::class,
            'status_changed_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function competitionEntry(): BelongsTo
    {
        return $this->belongsTo(CompetitionEntry::class);
    }

    public function isActive(): bool
    {
        return ($this->status ?? GroupPlayerStatus::Active) === GroupPlayerStatus::Active;
    }

    public function isInactive(): bool
    {
        return ! $this->isActive();
    }
}
