<?php

namespace App\Actions\Ranking;

use App\Data\Audit\AuditEntry;
use App\Enums\AuditAction;
use App\Models\Ranking;
use App\Support\Audit\AuditContextBuilder;
use App\Support\Audit\AuditLogger;
use App\Support\Ranking\RankingMutationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteRankingAction
{
    public const USED_RANKING_MESSAGE = 'Este ranking ya tiene puntos otorgados y no se puede eliminar. Podés desactivarlo para conservar su historial.';

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function __invoke(Ranking $ranking): void
    {
        if (RankingMutationGuard::hasHistory($ranking)) {
            throw ValidationException::withMessages([
                'ranking' => [self::USED_RANKING_MESSAGE],
            ]);
        }

        DB::transaction(function () use ($ranking): void {
            $ranking->load('category');

            $context = AuditContextBuilder::fromRanking($ranking);
            $snapshot = AuditContextBuilder::rankingSnapshot($ranking);
            $rankingId = $ranking->id;

            $this->auditLogger->log(new AuditEntry(
                action: AuditAction::RANKING_DELETED,
                logName: 'rankings',
                subject: $ranking,
                context: $context,
                old: $snapshot,
                summary: [
                    'ranking_id' => $rankingId,
                    'ranking_name' => $ranking->name,
                ],
            ));

            $ranking->delete();
        });
    }
}
