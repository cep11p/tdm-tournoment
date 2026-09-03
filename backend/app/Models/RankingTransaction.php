<?php

namespace App\Models;

use App\Enums\CompetitionFinalStandingSource;
use App\Enums\CompetitionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RankingTransaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'competition_type' => CompetitionType::class,
            'position' => 'integer',
            'position_range_end' => 'integer',
            'source' => CompetitionFinalStandingSource::class,
            'points' => 'integer',
        ];
    }

    public function ranking(): BelongsTo
    {
        return $this->belongsTo(Ranking::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(RankingRule::class, 'ranking_rule_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
