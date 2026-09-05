<?php

namespace App\Actions\Ranking;

use App\Enums\CompetitionType;
use App\Models\Ranking;
use App\Models\RankingRule;
use Illuminate\Validation\ValidationException;

final class CopyRankingRulesAction
{
    public const CROSS_TYPE_MESSAGE = 'No se pueden copiar las reglas de un ranking de otra modalidad.';

    public function __invoke(Ranking $source, Ranking $target): int
    {
        $sourceType = $this->typeValue($source);
        $targetType = $this->typeValue($target);

        if ($sourceType !== $targetType) {
            throw ValidationException::withMessages([
                'copy_rules_from_ranking_id' => [self::CROSS_TYPE_MESSAGE],
            ]);
        }

        $rules = $source->rules()->reorder()->orderBy('id')->get();
        $copied = 0;

        foreach ($rules as $rule) {
            RankingRule::query()->create([
                'ranking_id' => $target->id,
                'name' => $rule->name,
                'source' => $rule->source,
                'position' => $rule->position,
                'points' => $rule->points,
                'active' => $rule->active,
                'priority' => $rule->priority,
            ]);

            $copied++;
        }

        return $copied;
    }

    private function typeValue(Ranking $ranking): string
    {
        return $ranking->competition_type instanceof CompetitionType
            ? $ranking->competition_type->value
            : (string) $ranking->competition_type;
    }
}
