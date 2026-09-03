<?php

namespace App\Models;

use App\Enums\CompetitionFinalStandingSource;
use App\Support\Ranking\RankingRuleGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RankingRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => CompetitionFinalStandingSource::class,
            'points' => 'integer',
            'priority' => 'integer',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (RankingRule $rule): void {
            RankingRuleGuard::assertValid($rule);
        });
    }

    public function ranking(): BelongsTo
    {
        return $this->belongsTo(Ranking::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(RankingTransaction::class);
    }
}
