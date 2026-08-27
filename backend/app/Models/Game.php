<?php

namespace App\Models;

use App\Enums\BracketGamePurpose;
use App\Enums\GameStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Competitive identity of each side is always a CompetitionEntry of this
 * competition (singles player, doubles pair, or team). Concrete athletes in a
 * future TeamTie lineup do not change entry1/entry2/winnerEntry.
 *
 * @property int $entry1_id
 * @property int|null $entry2_id
 * @property int|null $winner_entry_id
 */
class Game extends Model
{
    use HasFactory;

    public const DISPLAY_RELATIONS = [
        'entry1.members.player:id,first_name,last_name,nickname',
        'entry2.members.player:id,first_name,last_name,nickname',
        'winnerEntry.members.player:id,first_name,last_name,nickname',
        'sets',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
            'table_number' => 'integer',
            'bracket_round' => 'integer',
            'bracket_match' => 'integer',
            'group_round' => 'integer',
            'group_match' => 'integer',
            'finished_at' => 'datetime',
            'is_bye' => 'boolean',
            'best_of' => 'integer',
            'sets_to_win' => 'integer',
            'bracket_purpose' => BracketGamePurpose::class,
        ];
    }

    /**
     * @param  Builder<Game>  $query
     */
    public function scopeMainBracket(Builder $query): Builder
    {
        return $query->where('bracket_purpose', BracketGamePurpose::Main);
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function bracket(): BelongsTo
    {
        return $this->belongsTo(Bracket::class);
    }

    public function entry1(): BelongsTo
    {
        return $this->belongsTo(CompetitionEntry::class, 'entry1_id');
    }

    public function entry2(): BelongsTo
    {
        return $this->belongsTo(CompetitionEntry::class, 'entry2_id');
    }

    public function winnerEntry(): BelongsTo
    {
        return $this->belongsTo(CompetitionEntry::class, 'winner_entry_id');
    }

    public function sets(): HasMany
    {
        return $this->hasMany(GameSet::class)->orderBy('set_number');
    }

    public function singlesPlayer1(): ?Player
    {
        return $this->entry1?->singlesPlayer();
    }

    public function singlesPlayer2(): ?Player
    {
        return $this->entry2?->singlesPlayer();
    }

    public function singlesWinner(): ?Player
    {
        return $this->winnerEntry?->singlesPlayer();
    }

    public function singlesPlayer1Id(): ?int
    {
        return $this->entry1?->singlesPlayerId();
    }

    public function singlesPlayer2Id(): ?int
    {
        return $this->entry2?->singlesPlayerId();
    }

    public function singlesWinnerId(): ?int
    {
        return $this->winnerEntry?->singlesPlayerId();
    }

    public function loserEntryId(): ?int
    {
        if ($this->winner_entry_id === null) {
            return null;
        }

        if ((int) $this->winner_entry_id === (int) $this->entry1_id) {
            return $this->entry2_id !== null ? (int) $this->entry2_id : null;
        }

        if ((int) $this->winner_entry_id === (int) $this->entry2_id) {
            return (int) $this->entry1_id;
        }

        return null;
    }

    /**
     * @return array{player1: int, player2: int}
     */
    public function setsWonCount(?Collection $sets = null): array
    {
        $sets ??= $this->relationLoaded('sets')
            ? $this->sets
            : $this->sets()->get();

        $player1Wins = 0;
        $player2Wins = 0;

        foreach ($sets as $set) {
            if ($set->player1_score > $set->player2_score) {
                $player1Wins++;
            } elseif ($set->player2_score > $set->player1_score) {
                $player2Wins++;
            }
        }

        return [
            'player1' => $player1Wins,
            'player2' => $player2Wins,
        ];
    }
}
