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

final class CreateRankingRuleAction
{
    public const DUPLICATE_MESSAGE = 'Ya existe una regla para este resultado en este ranking.';

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{result_key: string, points: int, active?: bool}  $payload
     */
    public function __invoke(Ranking $ranking, array $payload): RankingRule
    {
        return DB::transaction(function () use ($ranking, $payload): RankingRule {
            RankingRulesMutationGuard::assertMutable($ranking);

            $definition = RankingRuleCatalog::find((string) $payload['result_key']);

            if ($definition === null) {
                throw ValidationException::withMessages([
                    'result_key' => ['El resultado deportivo no es válido.'],
                ]);
            }

            $duplicateQuery = RankingRule::query()
                ->where('ranking_id', $ranking->id)
                ->where('source', $definition['source']->value);

            if ($definition['position'] === null) {
                $duplicateQuery->whereNull('position');
            } else {
                $duplicateQuery->where('position', $definition['position']);
            }

            if ($duplicateQuery->exists()) {
                throw ValidationException::withMessages([
                    'result_key' => [self::DUPLICATE_MESSAGE],
                ]);
            }

            $rule = RankingRule::query()->create([
                'ranking_id' => $ranking->id,
                'name' => $definition['name'],
                'source' => $definition['source'],
                'position' => $definition['position'],
                'points' => (int) $payload['points'],
                'priority' => 0,
                'active' => array_key_exists('active', $payload) ? (bool) $payload['active'] : true,
            ]);

            $resultKey = $definition['key'];

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::RANKING_RULE_CREATED,
                logName: 'rankings',
                subject: $rule,
                context: AuditContextBuilder::fromRankingRule($rule, $ranking),
                new: [
                    'result_key' => $resultKey,
                    'points' => (int) $rule->points,
                    'active' => (bool) $rule->active,
                ],
                summary: [
                    'ranking_id' => $ranking->id,
                    'result_key' => $resultKey,
                    'new_points' => (int) $rule->points,
                    'new_active' => (bool) $rule->active,
                ],
            ));

            return $rule;
        });
    }
}
