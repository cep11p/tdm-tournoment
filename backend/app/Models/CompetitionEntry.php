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
}
