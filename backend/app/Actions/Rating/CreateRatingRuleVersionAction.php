<?php

namespace App\Actions\Rating;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\RatingRuleVersionStatus;
use App\Models\RatingRuleVersion;
use App\Models\RatingSystem;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateRatingRuleVersionAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{rules?: list<array<string, mixed>>}  $payload
     */
    public function __invoke(RatingSystem $ratingSystem, array $payload): RatingRuleVersion
    {
        try {
            return $this->insert($ratingSystem, $payload);
        } catch (UniqueConstraintViolationException) {
            try {
                return $this->insert($ratingSystem, $payload);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'version' => ['No se pudo reservar un número de versión. Intentá de nuevo.'],
                ]);
            }
        }
    }

    /**
     * @param  array{rules?: list<array<string, mixed>>}  $payload
     */
    private function insert(RatingSystem $ratingSystem, array $payload): RatingRuleVersion
    {
        return DB::transaction(function () use ($ratingSystem, $payload): RatingRuleVersion {
            $system = RatingSystem::query()->lockForUpdate()->findOrFail($ratingSystem->id);

            $nextVersion = ((int) RatingRuleVersion::query()
                ->where('rating_system_id', $system->id)
                ->max('version')) + 1;

            $version = RatingRuleVersion::query()->create([
                'rating_system_id' => $system->id,
                'version' => $nextVersion,
                'status' => RatingRuleVersionStatus::Draft,
                'rules' => $payload['rules'] ?? [],
                'published_at' => null,
            ]);

            $version->setRelation('ratingSystem', $system);

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::RATING_VERSION_CREATED,
                logName: 'rating_systems',
                subject: $version,
                context: AuditContextBuilder::fromRatingRuleVersion($version, $system),
                new: [
                    'version' => $nextVersion,
                    'status' => RatingRuleVersionStatus::Draft->value,
                    'rules' => $version->rules ?? [],
                ],
                summary: [
                    'rating_system_id' => $system->id,
                    'rating_system_name' => $system->name,
                    'rating_version_id' => $version->id,
                    'version' => $nextVersion,
                ],
            ));

            return $version;
        });
    }
}
