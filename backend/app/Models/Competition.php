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

    public const TEAM_SIZE_MIN = 2;

    public const TEAM_SIZE_MAX = 20;

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
            'team_size' => 'integer',
        ];
    }

    public function expectedMemberCount(): int
    {
        $type = $this->type instanceof CompetitionType
            ? $this->type
            : CompetitionType::from((string) $this->type);

        return match ($type) {
            CompetitionType::Singles => 1,
            CompetitionType::Doubles => 2,
            CompetitionType::Team => $this->resolveTeamSize(),
        };
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

    private function resolveTeamSize(): int
    {
        if ($this->team_size === null) {
            throw new \LogicException('La competencia por equipos no tiene team_size configurado.');
        }

        return (int) $this->team_size;
    }
}
