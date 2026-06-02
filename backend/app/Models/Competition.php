<?php

namespace App\Models;

use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Competition extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => CompetitionType::class,
            'format' => CompetitionFormat::class,
            'sets_to_win' => 'integer',
            'points_per_set' => 'integer',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'competition_id');
    }
}
