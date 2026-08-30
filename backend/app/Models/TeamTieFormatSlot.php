<?php

namespace App\Models;

use App\Enums\TeamTieModality;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamTieFormatSlot extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'slot_order' => 'integer',
            'modality' => TeamTieModality::class,
        ];
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(TeamTieFormat::class, 'team_tie_format_id');
    }
}
