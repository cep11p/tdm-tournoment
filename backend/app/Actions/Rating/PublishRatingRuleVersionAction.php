<?php

namespace App\Actions\Rating;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Enums\RatingMethod;
use App\Enums\RatingRuleVersionStatus;
use App\Models\RatingRuleVersion;
use App\Models\RatingSystem;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Rating\DifferenceTableValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PublishRatingRuleVersionAction
{
    public const INACTIVE_SYSTEM_MESSAGE = 'No se puede publicar una versión mientras el sistema de rating está inactivo.';

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(RatingRuleVersion $version): RatingRuleVersion
    {
        return DB::transaction(function () use ($version): RatingRuleVersion {
            $locked = RatingRuleVersion::query()->lockForUpdate()->findOrFail($version->id);

            if ($locked->isPublished()) {
                throw ValidationException::withMessages([
                    'status' => ['Esta versión ya está publicada.'],
                ]);
            }

            $system = RatingSystem::query()->lockForUpdate()->findOrFail($locked->rating_system_id);

            if (! $system->active) {
                throw ValidationException::withMessages([
                    'rating_system' => [self::INACTIVE_SYSTEM_MESSAGE],
                ]);
            }

            $method = $system->method;

            if ($method !== RatingMethod::DifferenceTable) {
                throw ValidationException::withMessages([
                    'rules' => ['El método de cálculo no admite publicar esta tabla.'],
                ]);
            }

            $errors = DifferenceTableValidator::errors($locked->rules ?? []);

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $locked->status = RatingRuleVersionStatus::Published;
            $locked->published_at = now();
            $locked->save();
            $locked->refresh();

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::RATING_VERSION_PUBLISHED,
                logName: 'rating_systems',
                subject: $locked,
                context: AuditContextBuilder::fromRatingRuleVersion($locked, $system),
                old: [
                    'status' => RatingRuleVersionStatus::Draft->value,
                ],
                new: [
                    'status' => RatingRuleVersionStatus::Published->value,
                    'published_at' => $locked->published_at?->toIso8601String(),
                    'rules' => $locked->rules ?? [],
                ],
                summary: [
                    'rating_system_id' => $locked->rating_system_id,
                    'rating_system_name' => $system->name,
                    'rating_version_id' => $locked->id,
                    'version' => (int) $locked->version,
                    'published_at' => $locked->published_at?->toIso8601String(),
                ],
            ));

            return $locked;
        });
    }
}
