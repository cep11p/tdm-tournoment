<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamTieFormat extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'victories_required' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function slots(): HasMany
    {
        return $this->hasMany(TeamTieFormatSlot::class)->orderBy('slot_order');
    }

    public function competitions(): HasMany
    {
        return $this->hasMany(Competition::class);
    }

    public function teamTies(): HasMany
    {
        return $this->hasMany(TeamTie::class);
    }
}
