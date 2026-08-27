<?php

namespace App\Models;

use App\Enums\CompetitionFormat;
use App\Enums\CompetitionType;
use App\Enums\ThirdPlaceMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Competition extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = [
        'qualified_per_group' => 2,
        'group_stage_best_of' => 5,
        'knockout_stage_best_of' => 5,
        'semifinal_best_of' => 7,
        'final_best_of' => 7,
        'third_place_mode' => ThirdPlaceMode::Shared,
    ];

    protected function casts(): array
    {
        return [
            'type' => CompetitionType::class,
            'format' => CompetitionFormat::class,
            'sets_to_win' => 'integer',
            'points_per_set' => 'integer',
            'qualified_per_group' => 'integer',
            'group_stage_best_of' => 'integer',
            'knockout_stage_best_of' => 'integer',
            'semifinal_best_of' => 'integer',
            'final_best_of' => 'integer',
            'third_place_mode' => ThirdPlaceMode::class,
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function categoryModel(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CompetitionEntry::class);
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function brackets(): HasMany
    {
        return $this->hasMany(Bracket::class);
    }
}
