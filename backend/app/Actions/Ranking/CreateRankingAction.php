<?php

namespace App\Actions\Ranking;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Ranking;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Ranking\RankingMutationGuard;
use App\Support\Ranking\RankingTypeGuard;
use Illuminate\Support\Facades\DB;

final class CreateRankingAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CopyRankingRulesAction $copyRankingRules,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     competition_type: string,
     *     category_id?: int|null,
     *     season: string,
     *     active?: bool,
     *     starts_at?: string|null,
     *     ends_at?: string|null,
     *     copy_rules_from_ranking_id?: int|null
     * }  $payload
     */
    public function __invoke(array $payload): Ranking
    {
        return DB::transaction(function () use ($payload): Ranking {
            $copyFromId = isset($payload['copy_rules_from_ranking_id'])
                ? (int) $payload['copy_rules_from_ranking_id']
                : null;

            unset($payload['copy_rules_from_ranking_id']);

            RankingTypeGuard::assertSupported($payload['competition_type']);

            $ranking = Ranking::query()->create([
                'name' => $payload['name'],
                'competition_type' => $payload['competition_type'],
                'category_id' => $payload['category_id'] ?? null,
                'season' => $payload['season'],
                'active' => array_key_exists('active', $payload) ? (bool) $payload['active'] : false,
                'starts_at' => $payload['starts_at'] ?? null,
                'ends_at' => $payload['ends_at'] ?? null,
            ]);

            $copiedCount = 0;
            $source = null;

            if ($copyFromId !== null && $copyFromId > 0) {
                $source = Ranking::query()->findOrFail($copyFromId);
                $copiedCount = ($this->copyRankingRules)($source, $ranking);
            }

            if ($ranking->active) {
                RankingMutationGuard::assertCanActivate($ranking);
            }

            $ranking->refresh()->load(['category', 'rules'])->loadExists('transactions');

            $context = AuditContextBuilder::fromRanking($ranking);

            if ($source !== null) {
                $context['copied_from_ranking_id'] = $source->id;
                $context['rules_copied_count'] = $copiedCount;
            }

            $summary = [
                'ranking_id' => $ranking->id,
                'ranking_name' => $ranking->name,
            ];

            if ($source !== null) {
                $summary['copied_from_ranking_id'] = $source->id;
                $summary['rules_copied_count'] = $copiedCount;
            }

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::RANKING_CREATED,
                logName: 'rankings',
                subject: $ranking,
                context: $context,
                new: AuditContextBuilder::rankingSnapshot($ranking),
                summary: $summary,
            ));

            return $ranking;
        });
    }
}
