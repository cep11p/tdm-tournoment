<?php

namespace App\Models;

use App\Enums\TeamTieGameSide;
use App\Enums\TeamTieModality;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamTieGame extends Model
{
    use HasFactory;

    public const DISPLAY_RELATIONS = [
        'game',
        'members.competitionEntryMember.player:id,first_name,last_name,nickname',
        'teamTie.entry1',
        'teamTie.entry2',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'slot_order' => 'integer',
            'modality' => TeamTieModality::class,
        ];
    }

    public function teamTie(): BelongsTo
    {
        return $this->belongsTo(TeamTie::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(TeamTieGameMember::class)->orderBy('side')->orderBy('player_order');
    }

    public function isLineupComplete(): bool
    {
        $requiredPerSide = $this->modality === TeamTieModality::Doubles ? 2 : 1;

        if (! $this->relationLoaded('members')) {
            $this->load('members');
        }

        $entry1Count = $this->members
            ->filter(fn (TeamTieGameMember $member): bool => $member->side === TeamTieGameSide::Entry1)
            ->count();
        $entry2Count = $this->members
            ->filter(fn (TeamTieGameMember $member): bool => $member->side === TeamTieGameSide::Entry2)
            ->count();

        return $entry1Count === $requiredPerSide && $entry2Count === $requiredPerSide;
    }
}
