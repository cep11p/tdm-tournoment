<?php

namespace App\Models;

use App\Enums\CompetitionEntryStatus;
use App\Enums\CompetitionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompetitionEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CompetitionEntryStatus::class,
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(CompetitionEntryMember::class);
    }

    public function groupEntries(): HasMany
    {
        return $this->hasMany(GroupEntry::class);
    }

    public function teamTiesAsEntry1(): HasMany
    {
        return $this->hasMany(TeamTie::class, 'entry1_id');
    }

    public function teamTiesAsEntry2(): HasMany
    {
        return $this->hasMany(TeamTie::class, 'entry2_id');
    }

    public function wonTeamTies(): HasMany
    {
        return $this->hasMany(TeamTie::class, 'winner_entry_id');
    }

    /**
     * Solo válido para competencias de singles.
     *
     * @throws \LogicException si la competencia no es de tipo singles
     */
    public function singlesMember(): ?CompetitionEntryMember
    {
        $this->ensureSinglesCompetition();

        $members = $this->relationLoaded('members')
            ? $this->members
            : $this->members()->get();

        return $members->firstWhere('member_order', 1) ?? $members->first();
    }

    /**
     * Solo válido para competencias de singles.
     *
     * @throws \LogicException si la competencia no es de tipo singles
     */
    public function singlesPlayer(): ?Player
    {
        $member = $this->singlesMember();

        if ($member === null) {
            return null;
        }

        if ($member->relationLoaded('player')) {
            return $member->player;
        }

        return $member->player()->first();
    }

    /**
     * Solo válido para competencias de singles.
     *
     * @throws \LogicException si la competencia no es de tipo singles
     */
    public function singlesPlayerId(): ?int
    {
        $member = $this->singlesMember();

        return $member !== null ? (int) $member->player_id : null;
    }

    private function ensureSinglesCompetition(): void
    {
        $competition = $this->relationLoaded('competition')
            ? $this->competition
            : $this->competition()->first();

        $type = $competition?->type;

        if ($type instanceof CompetitionType && $type === CompetitionType::Singles) {
            return;
        }

        throw new \LogicException(
            'singlesMember(), singlesPlayer() y singlesPlayerId() solo aplican a competencias de singles.',
        );
    }
}
