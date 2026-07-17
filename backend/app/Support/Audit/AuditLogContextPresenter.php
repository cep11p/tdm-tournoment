<?php

namespace App\Support\Audit;

use Spatie\Activitylog\Models\Activity;

final class AuditLogContextPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Activity $activity): array
    {
        $context = data_get($activity->properties?->toArray() ?? [], 'context', []);

        return [
            'tournament_id' => data_get($context, 'tournament_id'),
            'tournament_name' => data_get($context, 'tournament_name'),
            'competition_id' => data_get($context, 'competition_id'),
            'competition_name' => data_get($context, 'competition_name'),
            'registration_id' => data_get($context, 'registration_id'),
            'player_id' => data_get($context, 'player_id'),
            'player_name' => data_get($context, 'player_name'),
            'group_id' => data_get($context, 'group_id'),
            'group_name' => data_get($context, 'group_name'),
            'bracket_id' => data_get($context, 'bracket_id'),
            'game_id' => data_get($context, 'game_id'),
            'player1_id' => data_get($context, 'player1_id'),
            'player1_name' => data_get($context, 'player1_name'),
            'player2_id' => data_get($context, 'player2_id'),
            'player2_name' => data_get($context, 'player2_name'),
        ];
    }
}
