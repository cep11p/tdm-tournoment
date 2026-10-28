<?php

namespace App\Models;

use App\Enums\RatingRuleVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class RatingRuleVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => RatingRuleVersionStatus::class,
            'rules' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (RatingRuleVersion $version): void {
            if ($version->getRawOriginal('status') === RatingRuleVersionStatus::Published->value) {
                throw ValidationException::withMessages([
                    'status' => ['Una versión publicada no se puede modificar.'],
                ]);
            }
        });

        static::deleting(function (RatingRuleVersion $version): void {
            $status = $version->status instanceof RatingRuleVersionStatus
                ? $version->status
                : RatingRuleVersionStatus::tryFrom((string) $version->status);

            if ($status === RatingRuleVersionStatus::Published) {
                throw ValidationException::withMessages([
                    'status' => ['No se puede eliminar una versión publicada.'],
                ]);
            }
        });
    }

    public function ratingSystem(): BelongsTo
    {
        return $this->belongsTo(RatingSystem::class);
    }

    public function isPublished(): bool
    {
        return $this->status === RatingRuleVersionStatus::Published;
    }
}
