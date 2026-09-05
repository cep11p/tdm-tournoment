<?php

namespace App\Actions\Ranking;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Ranking;
use App\Models\RankingRule;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Ranking\RankingRuleCatalog;
use App\Support\Ranking\RankingRulesMutationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeleteRankingRuleAction
{
    public const USED_RULE_MESSAGE = 'Esta regla ya otorgó puntos. No se puede eliminar.';

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(Ranking $ranking, RankingRule $rule): void
    {
        if ((int) $rule->ranking_id !== (int) $ranking->id) {
            throw new NotFoundHttpException;
        }

        RankingRulesMutationGuard::assertMutable($ranking);

        if ($rule->transactions()->exists()) {
            throw ValidationException::withMessages([
                'rule' => [self::USED_RULE_MESSAGE],
            ]);
        }

        DB::transaction(function () use ($ranking, $rule): void {
            $resultKey = RankingRuleCatalog::keyFor($rule->source, $rule->position);
            $oldPoints = (int) $rule->points;
            $oldActive = (bool) $rule->active;

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::RANKING_RULE_DELETED,
                logName: 'rankings',
                subject: $rule,
                context: AuditContextBuilder::fromRankingRule($rule, $ranking),
                old: [
                    'result_key' => $resultKey,
                    'points' => $oldPoints,
                    'active' => $oldActive,
                ],
                summary: [
                    'ranking_id' => $ranking->id,
                    'result_key' => $resultKey,
                    'old_points' => $oldPoints,
                    'old_active' => $oldActive,
                ],
            ));

            $rule->delete();
        });
    }
}
