<?php

namespace App\Models;

use App\Enums\CompetitionType;
use App\Support\Ranking\RankingTypeGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ranking extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'competition_type' => CompetitionType::class,
            'active' => 'boolean',
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Ranking $ranking): void {
            RankingTypeGuard::assertSupported($ranking->competition_type);
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(RankingRule::class)->orderByDesc('priority')->orderBy('id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(RankingTransaction::class);
    }

    public function standings(): HasMany
    {
        return $this->hasMany(RankingStanding::class);
    }
}
