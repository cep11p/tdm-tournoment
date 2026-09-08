<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BracketEntryOrigin extends Model
{
    public const REQUEST_MAP_ATTRIBUTE = 'bracket_entry_origins';

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

    /**
     * @return array{group_id: int, group_name: string, position: int}
     */
    public function toSidePayload(): array
    {
        return [
            'group_id' => (int) $this->group_id,
            'group_name' => (string) $this->group_name,
            'position' => (int) $this->group_position,
        ];
    }
}
