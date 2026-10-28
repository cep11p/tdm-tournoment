<?php

namespace App\Models;

use App\Enums\RatingMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RatingSystem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'initial_rating' => 'integer',
            'method' => RatingMethod::class,
            'active' => 'boolean',
        ];
    }

    public function ratingRuleVersions(): HasMany
    {
        return $this->hasMany(RatingRuleVersion::class)->orderByDesc('version');
    }
}
