<?php

namespace App\Actions\Registration;

use App\Models\Registration;
use Illuminate\Validation\ValidationException;

final class BulkRegisterPlayersToCompetitionAction
{
    public function __construct(
        private readonly RegisterPlayerToCompetitionAction $registerPlayer,
    ) {}

    /**
     * @param  array<int, int>  $playerIds
     * @return array{created: int, skipped: int, total: int}
     */
    public function __invoke(int $competitionId, array $playerIds): array
    {
        $uniqueIds = array_values(array_unique($playerIds));

        $existingPlayerIds = Registration::query()
            ->where('competition_id', $competitionId)
            ->whereIn('player_id', $uniqueIds)
            ->pluck('player_id')
            ->all();

        $existingSet = array_fill_keys($existingPlayerIds, true);

        $created = 0;
        $skipped = 0;

        foreach ($uniqueIds as $playerId) {
            if (isset($existingSet[$playerId])) {
                $skipped++;

                continue;
            }

            try {
                ($this->registerPlayer)([
                    'competition_id' => $competitionId,
                    'player_id' => $playerId,
                ]);

                $existingSet[$playerId] = true;
                $created++;
            } catch (ValidationException $exception) {
                if (isset($exception->errors()['player_id'])) {
                    $skipped++;

                    continue;
                }

                throw $exception;
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'total' => count($uniqueIds),
        ];
    }
}
