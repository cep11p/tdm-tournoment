<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BracketEntryOrigin extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'group_id' => 'integer',
            'group_position' => 'integer',
        ];
    }

    public function bracket(): BelongsTo
    {
        return $this->belongsTo(Bracket::class);
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function competitionEntry(): BelongsTo
    {
        return $this->belongsTo(CompetitionEntry::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
