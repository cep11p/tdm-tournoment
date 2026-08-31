<?php

namespace App\Models;

use App\Enums\TeamTieGameSide;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamTieGameMember extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'side' => TeamTieGameSide::class,
            'player_order' => 'integer',
        ];
    }

    public function teamTieGame(): BelongsTo
    {
        return $this->belongsTo(TeamTieGame::class);
    }

    public function competitionEntryMember(): BelongsTo
    {
        return $this->belongsTo(CompetitionEntryMember::class);
    }
}
