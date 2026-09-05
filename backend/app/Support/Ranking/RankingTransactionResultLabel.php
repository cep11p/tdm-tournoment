<?php

namespace App\Support\Ranking;

use App\Enums\CompetitionFinalStandingSource;
use App\Models\RankingTransaction;

final class RankingTransactionResultLabel
{
    public static function for(RankingTransaction $transaction): string
    {
        $snapshot = trim((string) ($transaction->ranking_rule_name_snapshot ?? ''));

        if ($snapshot !== '') {
            return $snapshot;
        }

        $source = $transaction->source instanceof CompetitionFinalStandingSource
            ? $transaction->source
            : CompetitionFinalStandingSource::tryFrom((string) $transaction->source);

        return $source?->label() ?? 'Resultado';
    }
}
