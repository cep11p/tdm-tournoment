<?php

namespace App\Actions\Ranking;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Ranking;
use App\Support\Audit\AuditChangeResolver;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Ranking\RankingMutationGuard;
use App\Support\Ranking\RankingTypeGuard;
use Illuminate\Support\Facades\DB;

final class UpdateRankingAction
{
    /**
     * @var list<string>
     */
    private const AUDITABLE_FIELDS = [
        'name',
        'competition_type',
        'category_id',
        'season',
        'active',
        'starts_at',
        'ends_at',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __invoke(Ranking $ranking, array $payload): Ranking
    {
        return DB::transaction(function () use ($ranking, $payload): Ranking {
            $wasActive = (bool) $ranking->active;

            $ranking->fill($payload);

            RankingMutationGuard::assertStructuralMutable($ranking);
            RankingTypeGuard::assertSupported($ranking->competition_type);

            $becomingActive = ! $wasActive && (bool) $ranking->active;

            if ($becomingActive) {
                RankingMutationGuard::assertCanActivate($ranking);
            }

            $changes = AuditChangeResolver::resolve($ranking, self::AUDITABLE_FIELDS);

            if ($changes === null) {
                return $ranking->load(['category', 'rules'])->loadExists('transactions');
            }

            $ranking->save();
            $ranking->refresh()->load(['category', 'rules'])->loadExists('transactions');

            $isActivation = ! $wasActive && (bool) $ranking->active;
            $isDeactivation = $wasActive && ! $ranking->active;

            $action = match (true) {
                $isActivation => AuditAction::RANKING_ACTIVATED,
                $isDeactivation => AuditAction::RANKING_DEACTIVATED,
                default => AuditAction::RANKING_UPDATED,
            };

            $this->auditLogger->log(new AuditEntry(
                action: $action,
                logName: 'rankings',
                subject: $ranking,
                context: AuditContextBuilder::fromRanking($ranking),
                old: $changes['old'],
                new: $changes['new'],
                summary: [
                    'ranking_id' => $ranking->id,
                    'ranking_name' => $ranking->name,
                    'changed_fields' => array_keys($changes['new']),
                ],
            ));

            return $ranking;
        });
    }
}
