<?php

namespace App\Actions\Rating;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\RatingRuleVersion;
use App\Support\Audit\AuditChangeResolver;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateRatingRuleVersionAction
{
    /**
     * @var list<string>
     */
    private const AUDITABLE_FIELDS = [
        'rules',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __invoke(RatingRuleVersion $version, array $payload): RatingRuleVersion
    {
        return DB::transaction(function () use ($version, $payload): RatingRuleVersion {
            $locked = RatingRuleVersion::query()->lockForUpdate()->findOrFail($version->id);
            $locked->loadMissing('ratingSystem');

            if ($locked->isPublished()) {
                throw ValidationException::withMessages([
                    'status' => ['Una versión publicada no se puede modificar.'],
                ]);
            }

            if (! array_key_exists('rules', $payload) || $locked->rules == $payload['rules']) {
                return $locked;
            }

            $locked->rules = $payload['rules'];

            $changes = AuditChangeResolver::resolve($locked, self::AUDITABLE_FIELDS);

            if ($changes === null) {
                return $locked;
            }

            $locked->save();
            $locked->refresh();

            $system = $locked->ratingSystem;

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::RATING_VERSION_UPDATED,
                logName: 'rating_systems',
                subject: $locked,
                context: AuditContextBuilder::fromRatingRuleVersion($locked, $system),
                old: $changes['old'],
                new: $changes['new'],
                summary: [
                    'rating_system_id' => $locked->rating_system_id,
                    'rating_system_name' => $system?->name,
                    'rating_version_id' => $locked->id,
                    'version' => (int) $locked->version,
                    'changed_fields' => array_keys($changes['new']),
                ],
            ));

            return $locked;
        });
    }
}
