<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bracket extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qualifiers_per_group' => 'integer',
            'bracket_size' => 'integer',
            'byes_count' => 'integer',
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    public function teamTies(): HasMany
    {
        return $this->hasMany(TeamTie::class);
    }

    public function entryOrigins(): HasMany
    {
        return $this->hasMany(BracketEntryOrigin::class);
    }

    /**
     * @return list<string>
     */
    public static function overviewRelations(Competition $competition): array
    {
        $prefix = $competition->isTeam() ? 'teamTies.' : 'games.';
        $nested = $competition->isTeam()
            ? TeamTie::BRACKET_OVERVIEW_RELATIONS
            : Game::DISPLAY_RELATIONS;

        return [
            'entryOrigins',
            ...array_map(
                fn (string $relation): string => $prefix.$relation,
                $nested,
            ),
        ];
    }
}
