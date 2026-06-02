<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Registration extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
