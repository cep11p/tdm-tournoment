<?php

namespace App\Models;

use App\Enums\RatingMethod;
use Illuminate\Database\Eloquent\Model;

class RatingSystem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'initial_rating' => 'integer',
            'method' => RatingMethod::class,
            'active' => 'boolean',
        ];
    }
}
