<?php

namespace App\Actions\Rating;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\RatingSystem;
use App\Support\Audit\AuditChangeResolver;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

final class UpdateRatingSystemAction
{
    /**
     * @var list<string>
     */
    private const AUDITABLE_FIELDS = [
        'name',
        'description',
        'initial_rating',
        'method',
        'active',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __invoke(RatingSystem $ratingSystem, array $payload): RatingSystem
    {
        return DB::transaction(function () use ($ratingSystem, $payload): RatingSystem {
            $ratingSystem = RatingSystem::query()->lockForUpdate()->findOrFail($ratingSystem->id);
            $wasActive = (bool) $ratingSystem->active;

            $ratingSystem->fill($payload);

            $changes = AuditChangeResolver::resolve($ratingSystem, self::AUDITABLE_FIELDS);

            if ($changes === null) {
                return $ratingSystem;
            }

            $ratingSystem->save();
            $ratingSystem->refresh();

            $isActivation = ! $wasActive && (bool) $ratingSystem->active;
            $isDeactivation = $wasActive && ! $ratingSystem->active;

            $action = match (true) {
                $isActivation => AuditAction::RATING_SYSTEM_ACTIVATED,
                $isDeactivation => AuditAction::RATING_SYSTEM_DEACTIVATED,
                default => AuditAction::RATING_SYSTEM_UPDATED,
            };

            $this->auditLogger->log(new AuditEntry(
                action: $action,
                logName: 'rating_systems',
                subject: $ratingSystem,
                context: AuditContextBuilder::fromRatingSystem($ratingSystem),
                old: $changes['old'],
                new: $changes['new'],
                summary: [
                    'rating_system_id' => $ratingSystem->id,
                    'rating_system_name' => $ratingSystem->name,
                    'changed_fields' => array_keys($changes['new']),
                ],
            ));

            return $ratingSystem;
        });
    }
}
