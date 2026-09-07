<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlayingTable extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function displayName(): string
    {
        $name = trim((string) ($this->name ?? ''));

        if ($name !== '') {
            return $name;
        }

        return sprintf('Mesa %d', $this->number);
    }

    /**
     * @param  Builder<PlayingTable>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }
}
