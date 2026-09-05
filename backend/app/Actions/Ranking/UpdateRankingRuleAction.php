<?php

namespace App\Actions\Ranking;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Ranking;
use App\Models\RankingRule;
use App\Support\Audit\AuditChangeResolver;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Ranking\RankingRuleCatalog;
use App\Support\Ranking\RankingRulesMutationGuard;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UpdateRankingRuleAction
{
    /**
     * @var list<string>
     */
    private const AUDITABLE_FIELDS = [
        'points',
        'active',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{points?: int, active?: bool}  $payload
     */
    public function __invoke(Ranking $ranking, RankingRule $rule, array $payload): RankingRule
    {
        return DB::transaction(function () use ($ranking, $rule, $payload): RankingRule {
            if ((int) $rule->ranking_id !== (int) $ranking->id) {
                throw new NotFoundHttpException;
            }

            RankingRulesMutationGuard::assertMutable($ranking);

            $wasActive = (bool) $rule->active;
            $oldPoints = (int) $rule->points;

            $rule->fill($payload);

            $changes = AuditChangeResolver::resolve($rule, self::AUDITABLE_FIELDS);

            if ($changes === null) {
                return $rule;
            }

            $rule->save();
            $rule->refresh();

            $isDeactivation = $wasActive
                && array_key_exists('active', $changes['new'])
                && $changes['new']['active'] === false;

            $resultKey = RankingRuleCatalog::keyFor($rule->source, $rule->position);
            $newPoints = (int) $rule->points;
            $newActive = (bool) $rule->active;

            $this->auditLogger->log(new AuditEntry(
                action: $isDeactivation
                    ? AuditAction::RANKING_RULE_DEACTIVATED
                    : AuditAction::RANKING_RULE_UPDATED,
                logName: 'rankings',
                subject: $rule,
                context: AuditContextBuilder::fromRankingRule($rule, $ranking),
                old: [
                    'result_key' => $resultKey,
                    'points' => $oldPoints,
                    'active' => $wasActive,
                ],
                new: [
                    'result_key' => $resultKey,
                    'points' => $newPoints,
                    'active' => $newActive,
                ],
                summary: [
                    'ranking_id' => $ranking->id,
                    'result_key' => $resultKey,
                    'old_points' => $oldPoints,
                    'new_points' => $newPoints,
                    'old_active' => $wasActive,
                    'new_active' => $newActive,
                ],
            ));

            return $rule;
        });
    }
}
