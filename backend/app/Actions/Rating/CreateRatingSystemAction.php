<?php

namespace App\Actions\Rating;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\RatingSystem;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

final class CreateRatingSystemAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     initial_rating: int,
     *     method: string,
     *     active?: bool
     * }  $payload
     */
    public function __invoke(array $payload): RatingSystem
    {
        return DB::transaction(function () use ($payload): RatingSystem {
            $ratingSystem = RatingSystem::query()->create([
                'name' => $payload['name'],
                'description' => $payload['description'] ?? null,
                'initial_rating' => $payload['initial_rating'],
                'method' => $payload['method'],
                'active' => $payload['active'] ?? true,
            ]);

            $ratingSystem->refresh();

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::RATING_SYSTEM_CREATED,
                logName: 'rating_systems',
                subject: $ratingSystem,
                context: AuditContextBuilder::fromRatingSystem($ratingSystem),
                new: AuditContextBuilder::ratingSystemSnapshot($ratingSystem),
                summary: [
                    'rating_system_id' => $ratingSystem->id,
                    'rating_system_name' => $ratingSystem->name,
                ],
            ));

            return $ratingSystem;
        });
    }
}
